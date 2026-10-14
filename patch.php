<?php

namespace Deployer;

use Symfony\Component\Console\Formatter\OutputFormatter;

/*
 * deploy:patch - patch the CURRENT release in place with the changes from a branch.
 *
 *   dep deploy:patch app.domain.tld                               # tip of the host's branch
 *   dep deploy:patch app.domain.tld -o patch_branch=hotfix/login  # a specific branch
 *   dep deploy:patch app.domain.tld -o patch_ref=a1b2c3d          # a specific commit/tag
 *   dep deploy:patch app.domain.tld -o patch_dry_run=true         # show + check only
 *   dep deploy:patch:list app.domain.tld                          # patches applied to current release
 *   dep deploy:patch:rollback app.domain.tld                      # undo the last patch
 *   dep deploy:patch:rollback app.domain.tld -o patch_rollback_all=true
 *
 * Speed: every remote command is a round trip, so the work is batched into as few as possible.
 *   1. probe     lock + current release + patch history + REVISION          (1 round trip)
 *   2. prepare   fetch ONLY the target branch, resolve commits, summary     (1 round trip)
 *   3. apply     diff -> patch file -> git apply -> save history            (1 round trip)
 *   4. post      only the tasks the changed files call for (patch_task_map)
 *   5. finish    unlock + tidy                                              (1 round trip)
 * Rollback = `git apply -R` of the stored patch, last-in-first-out.
 */

// Config

set('patch_branch', fn () => get('branch') ?: get('git_repo_branch', 'main'));
set('patch_ref', null);                  // commit/tag/sha, overrides the branch tip
set('patch_base', null);                 // commit the release is on, if it can't be detected
set('patch_dry_run', false);
set('patch_allow_downgrade', false);     // allow target that is behind the current commit
set('patch_rollback_all', false);
set('patch_migrate', true);              // run artisan:migrate if migrations changed
set('patch_lock', true);                 // hold the deploy lock while patching
set('patch_timing', true);               // print how long the patch took
set('patch_dir', '{{deploy_path}}/.dep/patches');
set('patch_repo', '{{deploy_path}}/.dep/repo');

// Tasks that always run after a patch/rollback, whatever changed
set('patch_post_tasks', []);

// regex on changed file paths => tasks to run. Order matters (vendors -> migrate -> optimize).
set('patch_task_map', [
    '~^composer\.(json|lock)$~' => ['deploy:vendors'],
    '~^(package(-lock)?\.json|(vite|webpack|tailwind|postcss)\.config\.\w+|webpack\.mix\.js|resources/(js|css|sass|scss|ts|images|fonts)/)~' => ['deploy:node_modules'],
    '~^database/(migrations|seeders)/~' => ['artisan:migrate'],
    '~^(config|routes|bootstrap)/|^resources/views/|^composer\.(json|lock)$|^\.env~' => ['artisan:optimize'],
]);

// Helpers

/** One round trip: (optionally) take the lock and read everything we need about the current release. */
function patchProbe(bool $lock): array
{
    $script = [
        'cd {{deploy_path}} || exit 1',
        '[ -L {{current_path}} ] || { echo ERR=nocurrent; exit 0; }',
        '[ -d {{patch_repo}} ] || { echo ERR=norepo; exit 0; }',
    ];

    if ($lock) {
        $script[] = 'mkdir -p {{deploy_path}}/.dep';
        $script[] = '( set -o noclobber; echo "deploy:patch ($(id -un))" > {{deploy_path}}/.dep/deploy.lock ) 2>/dev/null'
            . ' && echo LOCKED=me || echo "LOCKED=other:$(cat {{deploy_path}}/.dep/deploy.lock)"';
    }

    $script[] = 'path=$(readlink -f {{current_path}})';
    $script[] = 'rel=$(basename "$path")';
    $script[] = 'echo "PATH=$path"';
    $script[] = 'echo "REL=$rel"';
    $script[] = '[ -s {{patch_dir}}/$rel/stack.json ] && echo "STACK=$(cat {{patch_dir}}/$rel/stack.json)"';
    $script[] = '[ -f {{current_path}}/REVISION ] && echo "REVISION=$(head -c 64 {{current_path}}/REVISION)"';
    $script[] = 'true';

    $out = run(implode(' ; ', $script));

    preg_match_all('~^([A-Z]+)=(.*)$~m', $out, $matches, PREG_SET_ORDER);
    $v = [];
    foreach ($matches as $m) {
        $v[$m[1]] = trim($m[2]);
    }

    if (($v['ERR'] ?? '') === 'nocurrent') {
        throw new \RuntimeException('No current release found. Run deploy first.');
    }
    if (($v['ERR'] ?? '') === 'norepo') {
        throw new \RuntimeException('Git cache {{patch_repo}} not found. Run deploy first.');
    }
    if (str_starts_with($v['LOCKED'] ?? '', 'other:')) {
        throw new \RuntimeException(
            'Deploy locked by ' . substr($v['LOCKED'], 6) . ".\nExecute \"deploy:unlock\" task to unlock."
        );
    }
    if (($v['LOCKED'] ?? '') === 'me') {
        set('patch_locked', true);
    }

    // let the existing tasks (vendors, node_modules, artisan:*) work on the live release
    set('release_path', $v['PATH']);
    set('release_or_current_path', $v['PATH']);

    return [
        'path' => $v['PATH'],
        'release' => $v['REL'],
        'stack' => isset($v['STACK']) ? (json_decode($v['STACK'], true) ?: []) : [],
        'revision' => $v['REVISION'] ?? null,
    ];
}

function patchCurrentRev(array $ctx): string
{
    if ($ctx['stack']) {
        return end($ctx['stack'])['to'];
    }

    if ($base = get('patch_base')) {
        return $base;
    }

    // REVISION file written into the release by the deploy
    if ($ctx['revision'] && preg_match('~^[0-9a-f]{7,40}$~i', $ctx['revision'])) {
        return $ctx['revision'];
    }

    $log = run('cat {{deploy_path}}/.dep/releases_log 2>/dev/null || true');
    foreach (array_reverse(array_filter(explode("\n", $log))) as $line) {
        $entry = json_decode($line, true);
        if (($entry['release_name'] ?? null) === $ctx['release']) {
            $rev = $entry['revision'] ?? $entry['rev'] ?? null;
            if ($rev) {
                return $rev;
            }
        }
    }

    throw new \RuntimeException(
        "Cannot detect the commit release {$ctx['release']} is on. Pass it with -o patch_base=<sha>."
    );
}

function patchSection(string $out, string $name): string
{
    return preg_match('~^@@' . $name . '$\R?(.*?)(?=^@@|\z)~ms', $out, $m) ? trim($m[1]) : '';
}

function patchWriteStack(array $stack, string $release): string
{
    return 'printf %s ' . escapeshellarg(json_encode(array_values($stack)))
        . ' > {{patch_dir}}/' . $release . '/stack.json';
}

/** Builds ONE command that reverts the last $count patches. Returns [command, remaining stack, reverted entries]. */
function patchRevertPlan(array $stack, string $release, string $path, int $count): array
{
    $dir = '{{patch_dir}}/' . $release;
    $cmds = ['cd ' . escapeshellarg($path)];
    $reverted = [];

    while ($count-- > 0 && $stack) {
        $entry = array_pop($stack);
        $reverted[] = $entry;

        if ($entry['file']) {
            $cmds[] = "git apply --whitespace=nowarn -R $dir/{$entry['file']}";
        }
        $cmds[] = patchWriteStack($stack, $release);
        if ($entry['file']) {
            $cmds[] = "rm -f $dir/{$entry['file']}";
        }
    }

    return [implode(' && ', $cmds), $stack, $reverted];
}

// Output helpers. writeln() only prefixes the host on the first line of a string,
// so multi-line output is always printed one line at a time. Text is escaped so
// commit messages containing <tags> can't break the console formatter.

function patchLines(string $text, string $indent = '  ', string $style = ''): void
{
    foreach (explode("\n", rtrim($text)) as $line) {
        $line = OutputFormatter::escape($indent . $line);
        writeln($style ? "<$style>$line</>" : $line);
    }
}

function patchOk(string $message): void
{
    writeln('<fg=green>✔</> ' . OutputFormatter::escape($message));
}

function patchNote(string $message): void
{
    writeln('<fg=cyan>•</> ' . OutputFormatter::escape($message));
}

function patchWarn(string $message): void
{
    writeln('<fg=yellow>!</> ' . OutputFormatter::escape($message));
}

/** Keep long lists readable: first $max lines plus a "+N more" line. */
function patchTrim(string $text, int $max, string $what): string
{
    $lines = array_values(array_filter(explode("\n", $text), 'strlen'));
    if (count($lines) <= $max) {
        return implode("\n", $lines);
    }

    return implode("\n", array_slice($lines, 0, $max)) . "\n… " . (count($lines) - $max) . " more $what";
}

function patchInvoke(string $task): void
{
    if (Deployer::get()->tasks->has($task)) {
        invoke($task);
    } else {
        patchWarn("skipping $task (task not defined)");
    }
}

function patchPostTasks(array $files, bool $migrate = true): array
{
    $tasks = [];

    foreach (get('patch_task_map') as $regex => $list) {
        if (!preg_grep($regex, $files)) {
            continue;
        }

        foreach ((array) $list as $task) {
            if ($task === 'deploy:node_modules' && !get('hook_node_modules', false)) {
                continue;
            }
            if ($task === 'artisan:migrate' && (!$migrate || !get('patch_migrate'))) {
                continue;
            }
            $tasks[] = $task;
        }
    }

    return array_values(array_unique(array_merge($tasks, get('patch_post_tasks'))));
}

function patchUnlock(): void
{
    if (get('patch_locked')) {
        run('rm -f {{deploy_path}}/.dep/deploy.lock');
        set('patch_locked', false);
    }
}

// Tasks

desc('Lock, fetch only the target branch and work out what needs patching');
task('deploy:patch:prepare', function () {
    set('patch_noop', true);
    set('patch_started', microtime(true));

    $ctx = patchProbe((bool) get('patch_lock'));
    set('patch_release', $ctx['release']);
    set('patch_path', $ctx['path']);
    set('patch_stack', $ctx['stack']);

    $fromRev = patchCurrentRev($ctx);

    $url = escapeshellarg(get('repository'));
    $gc = '-c gc.auto=0 -c maintenance.auto=false -c protocol.version=2';

    if ($patchRef = get('patch_ref')) {
        $ref = $patchRef;
        // a commit we already have needs no network at all
        $fetch = '{ git cat-file -e ' . escapeshellarg($patchRef . '^{commit}') . ' 2>/dev/null'
            . " || git $gc fetch --quiet --no-tags $url '+refs/heads/*:refs/heads/*' 'refs/tags/*:refs/tags/*'; }";
    } else {
        $ref = 'refs/heads/' . get('patch_branch');
        $spec = escapeshellarg('+' . $ref . ':' . $ref);
        $fetch = "git $gc fetch --quiet --no-tags $url $spec";
    }

    $out = run(implode(' && ', [
        'cd {{patch_repo}}',
        'export GIT_TERMINAL_PROMPT=0',
        $fetch,
        'to=$(git rev-parse --verify ' . escapeshellarg($ref . '^{commit}') . ')',
        'from=$(git rev-parse --verify ' . escapeshellarg($fromRev . '^{commit}') . ')',
        'echo "TO=$to"',
        'echo "FROM=$from"',
        'echo "BEHIND=$(git merge-base --is-ancestor $to $from && echo 1 || echo 0)"',
        'echo "@@FILES"', 'git diff --name-only $from $to',
        'echo "@@LOG"', 'git log --oneline --no-decorate $from..$to',
        'echo "@@STAT"', 'git diff --stat $from $to',
    ]));

    preg_match('~^TO=([0-9a-f]+)$~m', $out, $t);
    preg_match('~^FROM=([0-9a-f]+)$~m', $out, $f);
    preg_match('~^BEHIND=(\d)$~m', $out, $b);
    $to = $t[1] ?? '';
    $from = $f[1] ?? '';

    if (!$to || !$from) {
        throw new \RuntimeException("Could not resolve commits.\n$out");
    }

    if ($from === $to) {
        patchNote("Release {$ctx['release']} is already at " . substr($to, 0, 8) . ', nothing to patch.');

        return;
    }

    if (($b[1] ?? '0') === '1' && !get('patch_allow_downgrade')) {
        throw new \RuntimeException(
            "Target $ref (" . substr($to, 0, 8) . ') is behind the current commit. '
            . 'Use -o patch_allow_downgrade=true if that is intended.'
        );
    }

    $files = array_values(array_filter(explode("\n", patchSection($out, 'FILES'))));

    $log = patchSection($out, 'LOG');
    $stat = patchSection($out, 'STAT');
    $statLines = array_values(array_filter(explode("\n", $stat), 'strlen'));
    $summary = array_pop($statLines); // "4 files changed, 116 insertions(+), 1 deletion(-)"
    $commits = $log === '' ? 0 : count(explode("\n", $log));
    $shortRef = preg_replace('~^refs/(heads|tags)/~', '', $ref);

    writeln('');
    writeln(sprintf(
        '<options=bold>Patching release %s</> <fg=cyan>%s</>  %s → %s',
        OutputFormatter::escape($ctx['release']),
        OutputFormatter::escape($shortRef),
        substr($from, 0, 8),
        substr($to, 0, 8)
    ));
    writeln('');

    if ($commits) {
        patchLines(patchTrim($log, 10, 'commits'));
        writeln('');
    }

    patchLines(patchTrim(implode("\n", $statLines), 15, 'files'));
    patchLines($summary ?? '', '  ', 'fg=cyan');
    writeln('');

    set('patch_from', $from);
    set('patch_to', $to);
    set('patch_files', $files);
    set('patch_noop', false);
});

desc('Create the patch file and apply it to the current release');
task('deploy:patch:apply', function () {
    if (get('patch_noop')) {
        return;
    }

    $release = get('patch_release');
    $from = get('patch_from');
    $to = get('patch_to');
    $files = get('patch_files');
    $dry = (bool) get('patch_dry_run');
    $dir = '{{patch_dir}}/' . $release;
    $name = date('YmdHis') . '-' . substr($from, 0, 8) . '-' . substr($to, 0, 8) . '.patch';
    $file = "$dir/$name";

    $entry = [
        'id' => date('YmdHis'),
        'file' => $files ? $name : null,
        'from' => $from,
        'to' => $to,
        'files' => $files,
        'time' => date('c'),
    ];
    $stack = array_merge(get('patch_stack'), [$entry]);

    // commits differ but the trees are identical (merge/rebase/empty commits): nothing to apply,
    // only move the tracked commit forward so the next patch starts from the right place
    if (!$files) {
        patchNote('Commits differ but no files changed.');
        set('patch_noop', true);

        if (!$dry) {
            run("mkdir -p $dir && " . patchWriteStack($stack, $release));
            set('patch_stack', $stack);
            patchOk('Release now tracked at ' . substr($to, 0, 8) . '.');
        }

        return;
    }

    $cmd = [
        "mkdir -p $dir",
        "git -C {{patch_repo}} diff --binary --full-index --no-color --no-ext-diff $from $to > $file",
        "{ [ -s $file ] || { echo 'Generated patch is empty' >&2; false; }; }",
        'cd ' . escapeshellarg(get('patch_path')),
        '{ git apply --whitespace=nowarn ' . ($dry ? '--check ' : '') . "$file || { rm -f $file; false; }; }",
    ];
    $cmd[] = $dry ? "rm -f $file" : patchWriteStack($stack, $release);

    run(implode(' && ', $cmd));

    if ($dry) {
        patchOk('Dry run: patch applies cleanly. Nothing changed.');
        set('patch_noop', true);

        return;
    }

    set('patch_applied', true);
    set('patch_stack', $stack);
    patchOk(sprintf('Patch applied: %s → %s (%d files)', substr($from, 0, 8), substr($to, 0, 8), count($files)));
});

desc('Run the follow-up tasks the changed files call for');
task('deploy:patch:post', function () {
    if (get('patch_noop')) {
        return;
    }

    $tasks = patchPostTasks(get('patch_files'));
    patchNote($tasks ? 'Post tasks: ' . implode(', ', $tasks) : 'No post tasks needed for these files.');

    foreach ($tasks as $task) {
        patchInvoke($task);
    }
});

desc('Unlock and tidy up patches of removed releases');
task('deploy:patch:finish', function () {
    $cmd = [];
    if (get('patch_locked')) {
        $cmd[] = 'rm -f {{deploy_path}}/.dep/deploy.lock';
        set('patch_locked', false);
    }
    $cmd[] = 'cd {{patch_dir}} 2>/dev/null && for d in *; do [ -d "$d" ] || continue; '
        . '[ -d {{deploy_path}}/releases/$d ] || rm -rf "$d"; done';
    $cmd[] = 'true';

    run(implode(' ; ', $cmd));

    if (get('patch_timing') && get('patch_started', null)) {
        patchOk(sprintf('Done in %.1fs', microtime(true) - get('patch_started')));
    }
});

desc('Roll back a failed patch and unlock');
task('deploy:patch:failed', function () {
    if (get('patch_applied')) {
        patchWarn('Patch failed, rolling it back.');

        [$cmd, $remaining] = patchRevertPlan(get('patch_stack'), get('patch_release'), get('patch_path'), 1);
        run($cmd);
        set('patch_stack', $remaining);
        set('patch_applied', false);

        foreach (patchPostTasks(get('patch_files'), false) as $task) {
            patchInvoke($task);
        }
    }

    patchUnlock();
});

desc('Patch the current release with changes from a branch (supports rollback)');
task('deploy:patch', [
    'deploy:patch:prepare',
    'deploy:patch:apply',
    'deploy:patch:post',
    'deploy:patch:finish',
]);
fail('deploy:patch', 'deploy:patch:failed');

desc('List the patches applied to the current release');
task('deploy:patch:list', function () {
    $ctx = patchProbe(false);

    if (!$ctx['stack']) {
        patchNote("No patches applied to release {$ctx['release']}.");

        return;
    }

    writeln("<options=bold>Release {$ctx['release']}</> " . count($ctx['stack']) . ' patch(es), newest first');
    foreach (array_reverse($ctx['stack']) as $i => $p) {
        writeln(sprintf(
            '  %s %s → %s  %2d files  %s%s',
            $p['id'],
            substr($p['from'], 0, 8),
            substr($p['to'], 0, 8),
            count($p['files']),
            $p['time'],
            $i === 0 ? '  <fg=cyan>← next rollback</>' : ''
        ));
    }
});

desc('Roll back the last patch (or all with -o patch_rollback_all=true)');
task('deploy:patch:rollback', function () {
    $ctx = patchProbe((bool) get('patch_lock'));

    try {
        if (!$ctx['stack']) {
            patchNote("No patches to roll back on release {$ctx['release']}.");

            return;
        }

        $count = get('patch_rollback_all') ? count($ctx['stack']) : 1;
        [$cmd, , $reverted] = patchRevertPlan($ctx['stack'], $ctx['release'], $ctx['path'], $count);
        run($cmd);

        $files = [];
        $migrated = false;
        foreach ($reverted as $entry) {
            patchOk(sprintf('Reverted %s: %s ← %s', $entry['id'], substr($entry['from'], 0, 8), substr($entry['to'], 0, 8)));
            $files = array_merge($files, $entry['files']);
            $migrated = $migrated || (bool) preg_grep('~^database/migrations/~', $entry['files']);
        }

        foreach (patchPostTasks($files, false) as $task) {
            patchInvoke($task);
        }

        if ($migrated) {
            patchWarn('The reverted patch contained migrations. The database was NOT rolled back; '
                . 'run artisan migrate:rollback manually if needed.');
        }
    } finally {
        patchUnlock();
    }
});
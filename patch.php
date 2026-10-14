<?php

namespace Deployer;

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
 * How it works
 *   1. Fetches the branch into Deployer's cached mirror ({{deploy_path}}/.dep/repo).
 *   2. Works out which commit the current release is on (last patch, else releases_log).
 *   3. Writes `git diff --binary <current>..<target>` to {{deploy_path}}/.dep/patches/<release>/.
 *   4. `git apply --check`, then `git apply` (atomic: all or nothing) in the current release.
 *   5. Runs only the follow-up tasks the changed files call for (vendors, node, migrate, optimize).
 *   6. Rollback = `git apply -R` of the stored patch, last-in-first-out.
 */

// Config

set('patch_branch', fn () => get('branch') ?: get('git_repo_branch', 'main'));
set('patch_ref', null);                  // commit/tag/sha, overrides the branch tip
set('patch_base', null);                 // commit the release is on, if it can't be detected
set('patch_dry_run', false);
set('patch_allow_downgrade', false);     // allow target that is behind the current commit
set('patch_rollback_all', false);
set('patch_migrate', true);              // run artisan:migrate if migrations changed
set('patch_post_tasks', ['artisan:optimize']);
set('patch_dir', '{{deploy_path}}/.dep/patches');
set('patch_repo', '{{deploy_path}}/.dep/repo');

// Helpers

function patchReleasePath(): string
{
    return trim(run('readlink -f {{current_path}}'));
}

function patchStackFile(string $release): string
{
    return "{{patch_dir}}/$release/stack.json";
}

function patchStack(string $release): array
{
    $file = patchStackFile($release);

    return test("[ -s $file ]") ? (json_decode(run("cat $file"), true) ?: []) : [];
}

function patchSaveStack(string $release, array $stack): void
{
    run('mkdir -p {{patch_dir}}/' . $release);
    run('printf %s ' . escapeshellarg(json_encode(array_values($stack))) . ' > ' . patchStackFile($release));
}

function patchCurrentRev(string $release): string
{
    $stack = patchStack($release);
    if ($stack) {
        return end($stack)['to'];
    }

    if ($base = get('patch_base')) {
        return $base;
    }

    // REVISION file written into the release by the deploy
    if (test('[ -f {{current_path}}/REVISION ]')) {
        $rev = trim(run('cat {{current_path}}/REVISION'));
        if (preg_match('~^[0-9a-f]{7,40}$~i', $rev)) {
            return $rev;
        }
    }

    if (test('[ -f {{deploy_path}}/.dep/releases_log ]')) {
        $lines = array_reverse(array_filter(explode("\n", run('cat {{deploy_path}}/.dep/releases_log'))));
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (($entry['release_name'] ?? null) === $release) {
                $rev = $entry['revision'] ?? $entry['rev'] ?? null;
                if ($rev) {
                    return $rev;
                }
            }
        }
    }

    throw new \RuntimeException(
        "Cannot detect the commit release $release is on. Pass it with -o patch_base=<sha>."
    );
}

function patchGit(string $args): string
{
    return trim(run('git -C {{patch_repo}} ' . $args, env: ['GIT_TERMINAL_PROMPT' => '0']));
}

function patchApply(string $file, bool $reverse = false): void
{
    $flags = '--whitespace=nowarn' . ($reverse ? ' -R' : '');
    $path = patchReleasePath();

    run("cd $path && git apply --check $flags $file");
    run("cd $path && git apply $flags $file");
}

function patchInvoke(string $task): void
{
    if (Deployer::get()->tasks->has($task)) {
        info("running $task");
        invoke($task);
    } else {
        warning("skipping $task (task not defined)");
    }
}

function patchPostTasks(array $files, bool $migrate = true): array
{
    $touched = fn (string $regex): bool => (bool) preg_grep($regex, $files);
    $tasks = [];

    if ($touched('~^composer\.(json|lock)$~')) {
        $tasks[] = 'deploy:vendors';
    }

    if (get('hook_node_modules', false)
        && $touched('~^(package(-lock)?\.json|vite\.config\.\w+|webpack\.mix\.js|resources/)~')) {
        $tasks[] = 'deploy:node_modules';
    }

    if ($migrate && get('patch_migrate') && $touched('~^database/(migrations|seeders)/~')) {
        $tasks[] = 'artisan:migrate';
    }

    return array_values(array_unique(array_merge($tasks, get('patch_post_tasks'))));
}

function patchRevert(array $entry, string $release): void
{
    if ($entry['file']) {
        $file = "{{patch_dir}}/$release/{$entry['file']}";
        patchApply($file, true);
    }

    $stack = patchStack($release);
    array_pop($stack);
    patchSaveStack($release, $stack);
    if ($entry['file']) {
        run("rm -f $file");
    }
}

// Tasks

desc('Lock the deployment while patching');
task('deploy:patch:lock', function () {
    invoke('deploy:lock');
    set('patch_locked', true);
});

desc('Fetch the branch and work out what needs patching');
task('deploy:patch:prepare', function () {
    set('patch_noop', true);

    if (!test('[ -L {{current_path}} ]')) {
        throw new \RuntimeException('No current release found. Run deploy first.');
    }
    if (!test('[ -d {{patch_repo}} ]')) {
        throw new \RuntimeException('Git cache {{patch_repo}} not found. Run deploy first.');
    }

    // let the existing tasks (vendors, node_modules, artisan:*) work on the live release
    $path = patchReleasePath();
    set('release_path', $path);
    set('release_or_current_path', $path);

    $release = basename($path);
    $from = patchGit('rev-parse --verify ' . escapeshellarg(patchCurrentRev($release) . '^{commit}'));

    patchGit('remote set-url origin ' . escapeshellarg(get('repository')));
    patchGit('remote update --prune');

    $ref = get('patch_ref') ?: 'refs/heads/' . get('patch_branch');
    $to = patchGit('rev-parse --verify ' . escapeshellarg($ref . '^{commit}'));

    if ($from === $to) {
        info("Release $release is already at " . substr($to, 0, 8) . ', nothing to patch.');

        return;
    }

    if (test('git -C {{patch_repo}} merge-base --is-ancestor ' . $to . ' ' . $from)
        && !get('patch_allow_downgrade')) {
        throw new \RuntimeException(
            "Target $ref (" . substr($to, 0, 8) . ') is behind the current commit. '
            . 'Use -o patch_allow_downgrade=true if that is intended.'
        );
    }

    $files = array_filter(explode("\n", patchGit("diff --name-only $from $to")));

    writeln('');
    writeln("<info>Release:</info> $release  <info>Source:</info> $ref");
    writeln('<info>Commits:</info>');
    writeln(patchGit("log --oneline --no-decorate $from..$to") ?: '  (none, diff only)');
    writeln('<info>Files:</info>');
    writeln(patchGit("diff --stat $from $to"));

    set('patch_release', $release);
    set('patch_from', $from);
    set('patch_to', $to);
    set('patch_files', array_values($files));
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
    $name = date('YmdHis') . '-' . substr($from, 0, 8) . '-' . substr($to, 0, 8) . '.patch';
    $file = "{{patch_dir}}/$release/$name";

    // commits differ but the trees are identical (merge/rebase/empty commits): nothing to apply,
    // only move the tracked commit forward so the next patch starts from the right place
    if (!get('patch_files')) {
        info('Commits differ but no files changed.');
        set('patch_noop', true);

        if (!get('patch_dry_run')) {
            $stack = patchStack($release);
            $stack[] = [
                'id' => date('YmdHis'),
                'file' => null,
                'from' => $from,
                'to' => $to,
                'files' => [],
                'time' => date('c'),
            ];
            patchSaveStack($release, $stack);
            info('Release now tracked at ' . substr($to, 0, 8) . '.');
        }

        return;
    }

    run('mkdir -p {{patch_dir}}/' . $release);
    patchGit("diff --binary --full-index --no-color --no-ext-diff $from $to > $file");

    if (!test("[ -s $file ]")) {
        run("rm -f $file");
        throw new \RuntimeException(
            'Generated patch is empty. Check: git -C {{patch_repo}} diff --stat ' . substr($from, 0, 8) . ' ' . substr($to, 0, 8)
        );
    }

    if (get('patch_dry_run')) {
        run('cd ' . patchReleasePath() . " && git apply --check --whitespace=nowarn $file");
        run("rm -f $file");
        info('Dry run: patch applies cleanly. Nothing changed.');
        set('patch_noop', true);

        return;
    }

    patchApply($file);
    set('patch_applied', true);

    $stack = patchStack($release);
    $stack[] = [
        'id' => date('YmdHis'),
        'file' => $name,
        'from' => $from,
        'to' => $to,
        'files' => get('patch_files'),
        'time' => date('c'),
    ];
    patchSaveStack($release, $stack);
    info('Patch applied: ' . substr($from, 0, 8) . ' -> ' . substr($to, 0, 8));
});

desc('Run the follow-up tasks the changed files call for');
task('deploy:patch:post', function () {
    if (get('patch_noop')) {
        return;
    }

    foreach (patchPostTasks(get('patch_files')) as $task) {
        patchInvoke($task);
    }
});

desc('Unlock and tidy up patches of removed releases');
task('deploy:patch:finish', function () {
    if (get('patch_locked')) {
        invoke('deploy:unlock');
        set('patch_locked', false);
    }

    run('cd {{patch_dir}} 2>/dev/null && for d in *; do [ -d "$d" ] || continue; '
        . '[ -d {{deploy_path}}/releases/$d ] || rm -rf "$d"; done || true');
});

desc('Roll back a failed patch and unlock');
task('deploy:patch:failed', function () {
    if (get('patch_applied')) {
        warning('Patch failed, rolling it back.');
        $release = get('patch_release');
        $stack = patchStack($release);
        if ($stack) {
            patchRevert(end($stack), $release);
            foreach (patchPostTasks(get('patch_files'), false) as $task) {
                patchInvoke($task);
            }
        }
        set('patch_applied', false);
    }

    if (get('patch_locked')) {
        invoke('deploy:unlock');
        set('patch_locked', false);
    }
});

desc('Patch the current release with changes from a branch (supports rollback)');
task('deploy:patch', [
    'deploy:patch:lock',
    'deploy:patch:prepare',
    'deploy:patch:apply',
    'deploy:patch:post',
    'deploy:patch:finish',
]);
fail('deploy:patch', 'deploy:patch:failed');

desc('List the patches applied to the current release');
task('deploy:patch:list', function () {
    $release = basename(patchReleasePath());
    $stack = patchStack($release);

    writeln("<info>Release $release</info>");
    if (!$stack) {
        writeln('No patches applied.');

        return;
    }

    foreach (array_reverse($stack) as $i => $p) {
        writeln(sprintf(
            '%s %s  %s -> %s  (%d files)  %s',
            $i === 0 ? '<info>latest</info>' : '      ',
            $p['id'],
            substr($p['from'], 0, 8),
            substr($p['to'], 0, 8),
            count($p['files']),
            $p['time']
        ));
    }
});

desc('Roll back the last patch (or all with -o patch_rollback_all=true)');
task('deploy:patch:rollback', function () {
    $release = basename(patchReleasePath());
    $path = patchReleasePath();
    set('release_path', $path);
    set('release_or_current_path', $path);

    $stack = patchStack($release);
    if (!$stack) {
        warning("No patches to roll back on release $release.");

        return;
    }

    invoke('deploy:lock');

    try {
        $files = [];
        $migrated = false;

        do {
            $entry = end($stack);
            info("Reverting {$entry['id']} (" . substr($entry['from'], 0, 8) . ' <- ' . substr($entry['to'], 0, 8) . ')');
            patchRevert($entry, $release);
            $files = array_merge($files, $entry['files']);
            $migrated = $migrated || (bool) preg_grep('~^database/migrations/~', $entry['files']);
            array_pop($stack);
        } while (get('patch_rollback_all') && $stack);

        foreach (patchPostTasks($files, false) as $task) {
            patchInvoke($task);
        }

        if ($migrated) {
            warning('The reverted patch contained migrations. The database was NOT rolled back; '
                . 'run artisan migrate:rollback manually if needed.');
        }
    } finally {
        invoke('deploy:unlock');
    }
});
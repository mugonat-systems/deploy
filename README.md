# Deployer Utils

This repository contains a collection of utility scripts for [Deployer](https://deployer.org/), a PHP deployment tool. These scripts streamline common deployment tasks.

```
composer require mugonat/deploy deployer/deployer --dev
```

Here's a typical `deploy.php`

```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/mugonat/deploy/utils.php';

// Config (Package Defaults)

set('hook_backup', false);
set('hook_backup_db', true);
set('hook_migrate_auto', true);
set('hook_node_modules', true);
set('hook_deploy_key', true);

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);

// Hosts

host('app.domain.tld')
    ->set('branch', 'deployment/client')
    ->set('http_user', 'site-user')
    ->setRemoteUser('site-user')
    ->setDeployPath('/home/{{remote_user}}/htdocs/{{hostname}}');

// Hooks

after('deploy:failed', 'deploy:unlock');
after('deploy:success', 'artisan:optimize');
after('push', 'artisan:optimize');
```

## Included Files

The `utils.php` file includes the following files:

### `env.php`

- **Purpose:** Provides a function `envGet()` to read configuration values from a `.env.deployer` file in the project root. It prefixes the variable names with `DEPLOYER_` but also checks for the unprefixed name.
- **Tasks:**
  * `env:backup`: Creates a backup of the `.env` file on the host.
  * `env:update`: Interactively updates or adds a variable in the `.env` file on the host. It fetches possible new variable names from the local `.env.example` and `.env` files, creates a backup first, and runs `artisan:optimize` after.
- **Options:** The options are the environment variables themselves, defined in the `.env.deployer` file. The script gives precedence to variables prefixed with `DEPLOYER_`.
- **Hooks:** None.

### `git.php`

- **Purpose:** Sets the `repository` and `branch` variables for Deployer based on environment variables from the `.env.deployer` file.
- **Options:**
  * `git_user`: The Git user. Defaults to `dev-mugonat`. Can be overridden by `DEPLOYER_GIT_USER` or `GIT_USER` in `.env.deployer`.
  * `git_password`: The Git password or token. Can be overridden by `DEPLOYER_GIT_PASS` or `GIT_PASS` in `.env.deployer`.
  * `git_repo`: The Git repository name. Can be overridden by `DEPLOYER_GIT_REPO` or `GIT_REPO` in `.env.deployer`.
  * `git_repo_path`: The path to the repository on the Git server. Defaults to the `git_user`. Can be overridden by `DEPLOYER_GIT_REPO_PATH` or `GIT_REPO_PATH` in `.env.deployer`.
  * `git_repo_branch`: The branch to deploy. Defaults to `main`. Can be overridden by `DEPLOYER_GIT_BRANCH` or `GIT_BRANCH` in `.env.deployer`.
  * `git_domain`: The Git domain. Defaults to `gitlab.com`. Can be overridden by `DEPLOYER_GIT_DOMAIN` or `GIT_DOMAIN` in `.env.deployer`.
- **Hooks:** None.

### `init.php`

- **Purpose:** Provides initialization tasks for the project.
- **Tasks:**
  * `env:init`: Creates a `.env.deployer` file from the example if it doesn't exist.
  * `nightwatch:init`: Creates a `.nightwatch` file from the example if it doesn't exist.
  * `utils:init`: A meta-task that runs `env:init` and `nightwatch:init`.
- **Options:** None.
- **Hooks:** None.

### `key.php`

- **Purpose:** Defines a task to generate an application key if it's missing in the `.env` file on the server.
- **Tasks:**
  * `deploy:key`: Generates the application key.
- **Options:**
  * `hook_deploy_key`: A boolean to enable or disable the `before('artisan:config:cache', 'deploy:key')` hook. Defaults to `true`.
- **Hooks:**
  * `before('artisan:config:cache', 'deploy:key')`: This task runs before `artisan:config:cache`.

### `migrate_auto.php`

- **Purpose:** Defines a task to run "auto" migrations.
- **Tasks:**
  * `artisan:migrate:auto`: Runs `php artisan migrate:auto --force --seed`.
- **Options:**
  * `hook_migrate_auto`: A boolean to enable or disable the `after('artisan:migrate', 'artisan:migrate:auto')` hook. Defaults to `true`.
- **Hooks:**
  * `after('artisan:migrate', 'artisan:migrate:auto')`: This task runs after `artisan:migrate`.

### `nightwatch.php`

- **Purpose:** Contains tasks for validating and configuring Nightwatch, a browser automation testing framework. It sets up a supervisor service to keep the Nightwatch agent running.
- **Tasks:**
  * `deploy:nightwatch:validate`: Checks if `supervisord` is running and if the deploy script is available.
  * `deploy:nightwatch`: The main task that configures Nightwatch. It creates a configuration file and sets up the supervisor service.
  * `deploy:nightwatch:configure`: A sub-task of `deploy:nightwatch` that handles the actual configuration and service setup.
- **Options:**
  * `nightwatch_port`: The port for the Nightwatch service. Defaults to `2048`. Can be overridden by `DEPLOYER_NIGHTWATCH_PORT` or `NIGHTWATCH_PORT` in `.env.deployer`.
  * `supervisor_deploy_script`: The path to the supervisor deploy script. Defaults to `/usr/local/bin/deploy-supervisor-config`.
- **Hooks:** None.

### `node_modules.php`

- **Purpose:** Defines a task to install npm dependencies and build the frontend assets.
- **Tasks:**
  * `deploy:node_modules`: Runs `npm install` and `npm run build`.
- **Options:**
  * `hook_node_modules`: A boolean to enable or disable the `after('deploy:vendors', 'deploy:node_modules')` hook. Defaults to `true`.
- **Hooks:**
  * `after('deploy:vendors', 'deploy:node_modules')`: This task runs after `deploy:vendors`.

### `patch.php`

- **Purpose:** Patches the **current release in place** with the changes from a branch (or a specific commit), without creating a new release. Every patch is stored on the host, so it can be rolled back. It is built for speed: remote work is batched into a handful of SSH round trips, only the target branch is fetched, and only the follow-up tasks the changed files need are run.
- **Tasks:**
  * `deploy:patch`: The main task. A meta-task that runs the sub-tasks below in order and rolls back automatically if a step fails after the patch was applied.
  * `deploy:patch:prepare`: In one round trip takes the deploy lock and reads the current release, its patch history and `REVISION`. In a second round trip fetches **only the target branch** into the cached mirror (`.dep/repo`), resolves the commits and prints a summary (commits and `diff --stat`). Does nothing if the release is already up to date. Refuses a target that is behind the current commit unless `patch_allow_downgrade` is set.
  * `deploy:patch:apply`: In one round trip writes `git diff --binary <current>..<target>` to `.dep/patches/<release>/`, applies it with `git apply` (atomic: either all changes land or none do) and saves the patch history.
  * `deploy:patch:post`: Runs only the follow-up tasks the changed files require (see `patch_task_map` below).
  * `deploy:patch:finish`: Unlocks, removes stored patches of releases that no longer exist, and prints how long the patch took.
  * `deploy:patch:failed`: Failure handler. Reverts the patch if it was already applied, re-runs the post tasks, and unlocks.
  * `deploy:patch:list`: Lists the patches applied to the current release, newest first.
  * `deploy:patch:rollback`: Reverse-applies the last patch (`git apply -R`) in a single round trip. Patches are rolled back last-in-first-out.
- **Usage:**
  ```bash
  dep deploy:patch app.domain.tld                               # tip of the host's `branch`
  dep deploy:patch app.domain.tld -o patch_branch=hotfix/login  # a specific branch
  dep deploy:patch app.domain.tld -o patch_ref=a1b2c3d          # a specific commit or tag
  dep deploy:patch app.domain.tld -o patch_dry_run=true         # summary + check only, nothing changes
  dep deploy:patch:list app.domain.tld
  dep deploy:patch:rollback app.domain.tld                      # undo the last patch
  dep deploy:patch:rollback app.domain.tld -o patch_rollback_all=true
  ```
- **Post-patch tasks:** After applying (and after a rollback) the tasks in `patch_task_map` whose pattern matches a changed file are run. The defaults:

  | Changed files | Task |
  | --- | --- |
  | `composer.json`, `composer.lock` | `deploy:vendors` |
  | `package.json`, `package-lock.json`, `vite/webpack/tailwind/postcss` config, `webpack.mix.js`, `resources/{js,css,sass,scss,ts,images,fonts}/**` | `deploy:node_modules` (only if `hook_node_modules` is `true`) |
  | `database/migrations/**`, `database/seeders/**` | `artisan:migrate` (apply only, never on rollback) |
  | `config/**`, `routes/**`, `bootstrap/**`, `resources/views/**`, `.env*`, `composer.*` | `artisan:optimize` |

  A change that only touches PHP files under `app/` therefore runs no follow-up task at all.
- **Options:**
  * `patch_branch`: The branch to pull changes from. Defaults to the host's `branch`, then `git_repo_branch`, then `main`.
  * `patch_ref`: A commit, tag, or SHA to patch to. Takes precedence over `patch_branch`. If the commit is already in the cached mirror, no network access is needed. Defaults to `null`.
  * `patch_base`: The commit the current release is on. Only needed if it can't be detected from the patch history, `current/REVISION`, or `.dep/releases_log`. Defaults to `null`.
  * `patch_dry_run`: Show the summary and check that the patch applies, without changing anything. Defaults to `false`.
  * `patch_allow_downgrade`: Allow patching to a commit that is behind the current one. Defaults to `false`.
  * `patch_rollback_all`: Makes `deploy:patch:rollback` revert every patch on the current release. Defaults to `false`.
  * `patch_migrate`: Run `artisan:migrate` when migrations or seeders changed. Defaults to `true`.
  * `patch_task_map`: Map of regex (matched against changed file paths) to the tasks to run. See the table above for the defaults. Add your own, e.g. `'~^app/Jobs/~' => ['artisan:queue:restart']`.
  * `patch_post_tasks`: Tasks that always run after a patch or rollback, whatever changed. Defaults to `[]`.
  * `patch_lock`: Hold the deploy lock while patching. Uses the same `.dep/deploy.lock` file as `deploy:lock`. Defaults to `true`.
  * `patch_timing`: Print how long the patch took. Defaults to `true`.
  * `patch_dir`: Where patches are stored on the host. Defaults to `{{deploy_path}}/.dep/patches`.
  * `patch_repo`: Deployer's cached git mirror on the host. Defaults to `{{deploy_path}}/.dep/repo`.

  Options can be set per host with `->set('patch_branch', '...')` or per run with `-o`.
- **Hooks:** None. `deploy:patch` is run on demand, and `fail('deploy:patch', 'deploy:patch:failed')` registers the automatic rollback.
- **Speed tips:**
  * Keep SSH multiplexing enabled (`ssh_multiplexing`, on by default) so each round trip reuses one connection.
  * If PHP's opcache runs with `validate_timestamps=0`, patched files are not picked up until opcache is reset. Add a reload task to `patch_post_tasks` or `patch_task_map` (for example `php-fpm:reload`, if you define one).
  * `deploy:vendors` and `deploy:node_modules` dominate the runtime when they run. Keeping dependency changes out of hotfix branches keeps patches fast.
- **Notes:**
  * A full `deploy` must have been run first, since patching needs a current release and the cached mirror.
  * Releases are exported without `.git`, which is why changes are applied as a diff rather than pulled inside the release.
  * Database changes are **not** reverted by a rollback. If a rolled-back patch contained migrations, a warning is shown and `php artisan migrate:rollback` must be run manually if needed.
  * Rolling back fails safely if the patched files were edited by hand after patching.
  * Patching a file that is a shared symlink (`shared_files`) will fail, since those files live outside the release.
  * A stale `.dep/deploy.lock` (for example after a crashed run) blocks patching, same as a normal deploy. Run `dep deploy:unlock`.
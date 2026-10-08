# Deployer Utils

Collection of utility recipes for [Deployer](https://deployer.org/) — Laravel-focused helpers for key generation, migrations, assets, backups, env management and Nightwatch.

> **Branch `1.x`** targets **Deployer 8.x** (`deployer/deployer ^8.0`, PHP `^8.3`). For Deployer 7.x use branch `0.x` (`mugonat/deploy ^0.1`).

```bash
composer require mugonat/deploy:^1.0 deployer/deployer:^8.0 --dev
```

Typical `deploy.php`:

```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/mugonat/deploy/utils.php';

// Package defaults — override in your deploy.php as needed
set('hook_backup', false);
set('hook_backup_db', true);
set('hook_node_modules', true);
set('hook_deploy_key', true);

set('hook_migrate_auto', false);
set('auto_migrate_seed', true);
set('auto_migrate_force', true);

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

See [`example/deploy.php`](example/deploy.php) and [`example/composer.json`](example/composer.json) for a runnable scaffold (`composer install` inside `example/` uses the local path repo).

## Compatibility

| Package branch | Deployer | PHP   | Install |
|---|---|---|---|
| `1.x` | `^8.0` | `^8.3` | `composer require mugonat/deploy:^1.0` |
| `0.x` | `^7.5` | `^8.0` | `composer require mugonat/deploy:^0.1` |

## Deployer 8 migration notes (applied on `1.x`)

Per https://deployer.org/docs/8.x/UPGRADE and https://deployer.org/docs/8.x/getting-started:

- `escapeshellarg()` → Deployer `quote()` (ANSI-C `$'...'` quoting) — applied in `node_modules.php`.
- `run()` options now use named arguments (`timeout:`, `nothrow:`); this repo had no affected calls — verified.
- Requires PHP 8.3+ and Symfony 7.4/8.0.
- **Deploy recipe changed:** Deployer 7 `deploy` ran `artisan:config:cache`, `artisan:route:cache`, `artisan:view:cache`, `artisan:event:cache` as separate steps. Deployer 8 `deploy` runs `artisan:optimize` (which wraps config/route/view caching) and adds `artisan:reload` after `deploy:publish`. Verified with `vendor/bin/dep tree deploy` in `example/` on each branch. The `deploy:key` hook was updated accordingly — `before('artisan:optimize')` for deploy (plus `before('artisan:config:cache')` kept for manual runs). Other hooks (`before artisan:migrate`, `after deploy:prepare/vendors`) remain valid on both trees.

## What `utils.php` loads

`utils.php` simply requires:

`env.php` → `git.php` → `key.php` → `migrate_auto.php` → `nightwatch.php` → `node_modules.php` → `init.php` → `backup.php`

You can also `require` individual files.

### `env.php`

* **Purpose:** `envGet(string $name, mixed $default = null)` reads `.env.deployer` from `getcwd()`. Checks `DEPLOYER_<NAME>` first, then `<NAME>`, strips surrounding quotes and coerces `true`/`false`/`null`/`empty`.
* **Tasks:**
  * `env:backup` — copies `{{release_or_current_path}}/.env` to `.env.backup-<timestamp>` if present.
  * `env:update` — interactive: backs up, parses remote `.env`, lets you update an existing key or add a new one (suggests names from local `.env.example`/`.env`), then `artisan:optimize`.
* **Hooks:** none.

### `git.php`

* **Purpose:** sets `repository` and `branch` from `.env.deployer`.
* **Config (via `envGet` / `get` fallback):**
  * `git_user` / `GIT_USER` / `DEPLOYER_GIT_USER` (default `dev-mugonat`)
  * `git_password` / `GIT_PASS` / `DEPLOYER_GIT_PASS`
  * `git_repo` / `GIT_REPO` / `DEPLOYER_GIT_REPO`
  * `git_repo_path` / `GIT_REPO_PATH` / `DEPLOYER_GIT_REPO_PATH` (default `git_user`)
  * `git_repo_branch` / `GIT_BRANCH` / `DEPLOYER_GIT_BRANCH` (default `main`)
  * `git_domain` / `GIT_DOMAIN` / `DEPLOYER_GIT_DOMAIN` (default `gitlab.com`)
* Builds `https://<user>:<pass>@<domain>/<path>/<repo>.git`.

### `init.php`

* **Purpose:** bootstrap helpers.
* **Tasks:**
  * `env:init` — copies `resources/.env.deployer.example` → `.env.deployer.example` and then `.env.deployer` (if missing).
  * `nightwatch:init` — copies `resources/.nightwatch` → `.nightwatch` (if missing).
  * `utils:init` — runs both.

### `key.php`

* **Tasks:** `deploy:key` — within `{{release_or_current_path}}`, checks `APP_KEY` is set and runs `{{bin/php}} artisan key:generate --force` only when missing (quiet on success via `info()`).
* **Config:** `hook_deploy_key` (default `true`) — `before('artisan:optimize')` **and** `before('artisan:config:cache')` on `1.x` (Deployer 8 deploy uses `optimize`; `config:cache` kept for manual runs). On `0.x` it was `before('artisan:config:cache')` only.

### `migrate_auto.php`

* **Purpose:** optional replacement for the stock `artisan:migrate`.
* **Tasks:**
  * `artisan:migrate:auto` — runs `migrate:auto` with flags derived from config.
  * `artisan:migrate` — **overridden**; at runtime delegates to `artisan:migrate:auto` when `hook_migrate_auto` is true, otherwise runs the stock `migrate --force` (both with `skipIfNoEnv`).
* **Config:**
  * `hook_migrate_auto` (default `true` in this file; `false` in example — set per host)
  * `auto_migrate_force` (default `true`)
  * `auto_migrate_seed` (default `true`)

### `nightwatch.php`

* **Purpose:** Nightwatch browser-agent setup via supervisor.
* **Tasks:**
  * `nightwatch:find-port` — scans `2048–3048` for a free port, sets `nightwatch_port`.
  * `nightwatch:validate` — checks `supervisord` running, wrapper script exists/executable, supervisor conf dir present.
  * `nightwatch:setup` — validates, renders `resources/.nightwatch` (or local `.nightwatch`) with `{{bin/php}}`/`{{current_path}}`/`{{port}}`/`{{hostname}}` replacements, uploads via `{{deploy_path}}`, invokes `nightwatch:configure`.
  * `nightwatch:configure` — uploads compiled conf to `{{deploy_path}}/<host>.conf` and runs `sudo <supervisor_deploy_script> <remotePath> <host>-nightwatch-agent`.
  * `nightwatch:status` — `{{bin/php}} artisan nightwatch:status` in `{{current_path}}`.
  * `nightwatch` — interactive: clear optimize, find port, ask token, confirm, backup/update `{{deploy_path}}/shared/.env` (`NIGHTWATCH_TOKEN`, `NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1`, `NIGHTWATCH_INGEST_URI=127.0.0.1:<port>`), then `nightwatch:setup` + `artisan:optimize`.
* **Config:**
  * `nightwatch_port` (default `2048`, via `envGet('NIGHTWATCH_PORT')`)
  * `supervisor_deploy_script` (default `/usr/local/bin/deploy-supervisor-config`)

### `node_modules.php`

* **Tasks:** `deploy:node_modules` — `cd {{release_or_current_path}}`, runs `{{node_install_command}}` then each `{{node_build_scripts}}` entry (`npm run <script>` quoted via `quote()`), always cleans `node_modules` afterwards.
* **Config:**
  * `hook_node_modules` (default `true`) — `after('deploy:vendors', 'deploy:node_modules')`
  * `node_install_command` (default `npm ci`)
  * `node_build_scripts` (default `['build']`, string or array)

### `backup.php`

* **Tasks:** `backup:database` / `backup` / `backup:cleanup` — thin wrappers around `artisan('backup:run …')` (spatie/laravel-backup).
* **Config:** `hook_backup_db` (`true`), `hook_backup` (`false`) — `before('artisan:migrate', 'backup:database')` and `after('deploy:prepare', 'backup')`.
* Requires `spatie/laravel-backup` in the host app.

## Recent changes

- **env tasks** (`env:backup`, `env:update`) — backup + interactive add/update of remote `.env` with local suggestions.
- **Deploy hooks** — `hook_backup_db` / `hook_backup` split, defaults `hook_backup_db=true`, `hook_backup=false`; other hooks (`hook_node_modules`, `hook_deploy_key`) deferred via conditional wrappers.
- **node_modules** — `node_install_command` / `node_build_scripts` configurability, `quote()` for script names, cleanup in `finally`.
- **migrate_auto** — now overrides `artisan:migrate` at runtime (instead of `after` hook); `auto_migrate_force`/`auto_migrate_seed` flags; `skipIfNoEnv`.
- **key** — quiet when `APP_KEY` already present; uses `info()` on generation; `1.x` now hooks `before artisan:optimize` (Deployer 8) in addition to `config:cache`.
- **nightwatch** — port discovery (`nightwatch:find-port`), validation, interactive `nightwatch` setup, `artisan:optimize:clear` integration, template fallback.
- **Example scaffold** — `example/deploy.php` + `example/composer.json` (path repo) added.
- **Deployer 8** — `1.x` branch: `quote()` migration, `composer.json` requires `deployer ^8.0` / `php ^8.3`, deploy tree `artisan:optimize`/`artisan:reload` verified.

## Docs

- Deployer 8: https://deployer.org/docs/8.x/getting-started and https://deployer.org/docs/8.x/UPGRADE
- Deployer 7: https://deployer.org/docs/7.x/getting-started
- Resources: `resources/.env.deployer.example`, `resources/.nightwatch`


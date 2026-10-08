# Deployer Utils

Collection of utility recipes for [Deployer](https://deployer.org/) — Laravel helpers for key generation, migrations, assets, backups, env management and Nightwatch.

> **Branch `0.x`** targets **Deployer 7.x** (`deployer/deployer ^7.5`). For Deployer 8.x use branch `1.x` (`mugonat/deploy ^1.0`).

```bash
composer require mugonat/deploy:^0.1 deployer/deployer:^7.5 --dev
```

Typical `deploy.php`:

```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/mugonat/deploy/utils.php';

// Package defaults — override in your deploy.php
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

See [`example/deploy.php`](example/deploy.php) and [`example/composer.json`](example/composer.json) for a runnable scaffold.

## Compatibility

| Branch | Deployer | PHP | Install |
|---|---|---|---|
| `1.x` | `^8.0` | `^8.3` | `composer require mugonat/deploy:^1.0` |
| `0.x` | `^7.5` | `^8.0` | `composer require mugonat/deploy:^0.1` |

## What `utils.php` loads

`env.php` → `git.php` → `key.php` → `migrate_auto.php` → `nightwatch.php` → `node_modules.php` → `init.php` → `backup.php`

### `env.php`

* `envGet(string $name, mixed $default = null)` — reads `.env.deployer` from `getcwd()`, prefers `DEPLOYER_<NAME>` then `<NAME>`, strips quotes, coerces `true`/`false`/`null`/`empty`.
* **Tasks:** `env:backup` (copies `{{release_or_current_path}}/.env` to `.env.backup-<timestamp>`), `env:update` (interactive update/add with suggestions from local `.env.example`/`.env`, then `artisan:optimize`).

### `git.php`

Sets `repository`/`branch` from `.env.deployer` (`GIT_USER`/`GIT_PASS`/`GIT_REPO`/`GIT_REPO_PATH`/`GIT_BRANCH`/`GIT_DOMAIN` and `DEPLOYER_` prefixed variants; defaults `dev-mugonat` / `main` / `gitlab.com`).

### `init.php`

* `env:init` — scaffold `.env.deployer.example` / `.env.deployer`
* `nightwatch:init` — scaffold `.nightwatch`
* `utils:init` — both

### `key.php`

* `deploy:key` — within `{{release_or_current_path}}`, generates `APP_KEY` only when missing (`hook_deploy_key` before `artisan:config:cache`, default `true`).

### `migrate_auto.php`

* `artisan:migrate:auto` — `migrate:auto` with `auto_migrate_force`/`auto_migrate_seed`
* `artisan:migrate` — **overridden**; delegates to `migrate:auto` when `hook_migrate_auto` is true, else stock `migrate --force` (both `skipIfNoEnv`). Defaults `hook_migrate_auto=true` (file) / `false` in example.

### `nightwatch.php`

* `nightwatch:find-port` (2048–3048 scan), `nightwatch:validate`, `nightwatch:setup`, `nightwatch:configure`, `nightwatch:status`, and interactive `nightwatch` (port + token → update `shared/.env` → setup → optimize). Config `nightwatch_port` / `supervisor_deploy_script`.

### `node_modules.php`

* `deploy:node_modules` — `cd {{release_or_current_path}}`, `{{node_install_command}}` (`npm ci`) + each `{{node_build_scripts}}` (`['build']`), cleans `node_modules` in `finally`. Hook `after('deploy:vendors', …)` via `hook_node_modules`.

### `backup.php`

* `backup:database` / `backup` / `backup:cleanup` (`artisan backup:run …` via spatie/laravel-backup). `hook_backup_db=true` (`before artisan:migrate`), `hook_backup=false` (`after deploy:prepare`).

## Recent changes

- `env:backup` / `env:update` added
- Hook split `hook_backup` / `hook_backup_db` (defaults `false` / `true`) with deferred conditional wrappers
- `node_modules` — configurable `node_install_command`/`node_build_scripts`, `finally` cleanup
- `migrate_auto` — runtime override of `artisan:migrate`, `auto_migrate_force`/`seed`, `skipIfNoEnv`
- `key` — quiet when key exists, `info()` on generation
- `nightwatch` — port discovery, validation, interactive setup, template fallback
- Example scaffold `example/deploy.php` + `example/composer.json` added
- `1.x` branch created for Deployer 8 (`quote()`, `php ^8.3`, `deployer ^8.0`)


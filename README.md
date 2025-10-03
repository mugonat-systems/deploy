# Deployer Utils

This repository contains a collection of utility scripts for [Deployer](https://deployer.org/), a PHP deployment tool. These scripts streamline common deployment tasks.

```bash
composer require mugonat/deploy deployer/deployer --dev
```

Here's a typical `deploy.php`
```php
<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'vendor/mugonat/deploy/utils.php';

// Config

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

*   **Purpose:** Provides a function `envGet()` to read configuration values from a `.env.deployer` file in the project root. It prefixes the variable names with `DEPLOYER_` but also checks for the unprefixed name.
*   **Options:** The options are the environment variables themselves, defined in the `.env.deployer` file. The script gives precedence to variables prefixed with `DEPLOYER_`.
*   **Hooks:** None.

### `git.php`

*   **Purpose:** Sets the `repository` and `branch` variables for Deployer based on environment variables from the `.env.deployer` file.
*   **Options:**
    *   `git_user`: The Git user. Defaults to `dev-mugonat`. Can be overridden by `DEPLOYER_GIT_USER` or `GIT_USER` in `.env.deployer`.
    *   `git_password`: The Git password or token. Can be overridden by `DEPLOYER_GIT_PASS` or `GIT_PASS` in `.env.deployer`.
    *   `git_repo`: The Git repository name. Can be overridden by `DEPLOYER_GIT_REPO` or `GIT_REPO` in `.env.deployer`.
    *   `git_repo_path`: The path to the repository on the Git server. Defaults to the `git_user`. Can be overridden by `DEPLOYER_GIT_REPO_PATH` or `GIT_REPO_PATH` in `.env.deployer`.
    *   `git_repo_branch`: The branch to deploy. Defaults to `main`. Can be overridden by `DEPLOYER_GIT_BRANCH` or `GIT_BRANCH` in `.env.deployer`.
    *   `git_domain`: The Git domain. Defaults to `gitlab.com`. Can be overridden by `DEPLOYER_GIT_DOMAIN` or `GIT_DOMAIN` in `.env.deployer`.
*   **Hooks:** None.

### `init.php`

*   **Purpose:** Provides initialization tasks for the project.
*   **Tasks:**
    *   `env:init`: Creates a `.env.deployer` file from the example if it doesn't exist.
    *   `nightwatch:init`: Creates a `.nightwatch` file from the example if it doesn't exist.
    *   `utils:init`: A meta-task that runs `env:init` and `nightwatch:init`.
*   **Options:** None.
*   **Hooks:** None.

### `key.php`

*   **Purpose:** Defines a task to generate an application key if it's missing in the `.env` file on the server.
*   **Tasks:**
    *   `deploy:key`: Generates the application key.
*   **Options:**
    *   `hook_deploy_key`: A boolean to enable or disable the `before('artisan:config:cache', 'deploy:key')` hook. Defaults to `true`.
*   **Hooks:**
    *   `before('artisan:config:cache', 'deploy:key')`: This task runs before `artisan:config:cache`.

### `migrate_auto.php`

*   **Purpose:** Defines a task to run "auto" migrations.
*   **Tasks:**
    *   `artisan:migrate:auto`: Runs `php artisan migrate:auto --force --seed`.
*   **Options:**
    *   `hook_migrate_auto`: A boolean to enable or disable the `after('artisan:migrate', 'artisan:migrate:auto')` hook. Defaults to `true`.
*   **Hooks:**
    *   `after('artisan:migrate', 'artisan:migrate:auto')`: This task runs after `artisan:migrate`.

### `nightwatch.php`

*   **Purpose:** Contains tasks for validating and configuring Nightwatch, a browser automation testing framework. It sets up a supervisor service to keep the Nightwatch agent running.
*   **Tasks:**
    *   `deploy:nightwatch:validate`: Checks if `supervisord` is running and if the deploy script is available.
    *   `deploy:nightwatch`: The main task that configures Nightwatch. It creates a configuration file and sets up the supervisor service.
    *   `deploy:nightwatch:configure`: A sub-task of `deploy:nightwatch` that handles the actual configuration and service setup.
*   **Options:**
    *   `nightwatch_port`: The port for the Nightwatch service. Defaults to `2048`. Can be overridden by `DEPLOYER_NIGHTWATCH_PORT` or `NIGHTWATCH_PORT` in `.env.deployer`.
    *   `supervisor_deploy_script`: The path to the supervisor deploy script. Defaults to `/usr/local/bin/deploy-supervisor-config`.
*   **Hooks:** None.

### `node_modules.php`

*   **Purpose:** Defines a task to install npm dependencies and build the frontend assets.
*   **Tasks:**
    *   `deploy:node_modules`: Runs `npm install` and `npm run build`.
*   **Options:**
    *   `hook_node_modules`: A boolean to enable or disable the `after('deploy:vendors', 'deploy:node_modules')` hook. Defaults to `true`.
*   **Hooks:**
    *   `after('deploy:vendors', 'deploy:node_modules')`: This task runs after `deploy:vendors`.

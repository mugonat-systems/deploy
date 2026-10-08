<?php

namespace Deployer;

require 'recipe/laravel.php';
require __DIR__ . '/../utils.php';

// Config (Package Defaults)

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
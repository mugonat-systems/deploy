<?php

namespace Deployer;

// Load the Laravel recipe first so artisan:migrate exists (and we replace it afterwards)
require_once 'recipe/laravel.php';

set('hook_migrate_auto', true);      // true = use migrate:auto instead of migrate
set('auto_migrate_seed', true);
set('auto_migrate_force', true);

desc('Run migrate:auto with options');
task('artisan:migrate:auto', function () {
    $force = get('auto_migrate_force') ? ' --force' : '';
    $seed  = get('auto_migrate_seed') ? ' --seed' : '';

    artisan("migrate:auto$force$seed", ['skipIfNoEnv'])();
});

// Override the stock task; the decision is made at run time
desc('Run artisan migrate, or migrate:auto when hook_migrate_auto is enabled');
task('artisan:migrate', function () {
    if (get('hook_migrate_auto')) {
        invoke('artisan:migrate:auto');
    } else {
        // original Laravel recipe behavior
        artisan('migrate --force', ['skipIfNoEnv'])();
    }
});
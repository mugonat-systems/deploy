<?php

namespace Deployer;

desc('Migrate auto migration models');
task('artisan:migrate:auto', function () {
    artisan('migrate:auto --force --seed')();
});

set('hook_migrate_auto', true);

after('artisan:migrate', function () {
    if (get('hook_migrate_auto')) {
        invoke('artisan:migrate:auto');
    }
});

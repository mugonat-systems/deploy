<?php

namespace Deployer;

desc('Migrate auto migration models');
task('artisan:migrate:auto', function () {
    artisan('migrate:auto --force --seed')();
});

set('hook_migrate_auto', true);

if (get('hook_migrate_auto')) {
    after('artisan:migrate', 'artisan:migrate:auto');
}

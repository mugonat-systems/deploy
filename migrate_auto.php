<?php

namespace Deployer;

desc('Migrate auto migration models');
task('artisan:migrate:auto', function () {
    artisan('migrate:auto --force --seed')();
});

after('artisan:migrate', 'artisan:migrate:auto');

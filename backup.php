<?php

namespace Deployer;

desc('Backup existing database - using spatie/laravel-backup');
task('backup:database', artisan('backup:run --only-db'));

desc('Backup application (including database) - using spatie/laravel-backup');
task('backup', artisan('backup:run'));

desc('Clean up application backups - using spatie/laravel-backup');
task('backup:cleanup', artisan('backup:cleanup'));

// Default to backing up database only
set('hook_backup_db', true);
set('hook_backup', false);

before('artisan:migrate', function () {
    if (get('hook_backup_db')) {
        invoke('backup:database');
    }
});

after('deploy:prepare', function () {
    if (get('hook_backup')) {
        invoke('backup');
    }
});

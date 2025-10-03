<?php

namespace Deployer;

desc('Generate application key if missing');
task('deploy:key', function () {
    within('{{release_or_current_path}}', function () {
        // Check if APP_KEY exists and is not empty
        $keyExists = test('grep -q "^APP_KEY=" .env && ! grep -q "^APP_KEY=$" .env');

        if (!$keyExists) {
            run('{{bin/php}} artisan key:generate --force');
            writeln('<info>Application key generated</info>');
        } else {
            $currentKey = run('grep "^APP_KEY=" .env | head -1');
            writeln('<comment>⚠️  APP_KEY already exists: ' . trim($currentKey) . '</comment>');
        }
    });
});

set('hook_deploy_key', true);

before('artisan:config:cache', function () {
    if (get('hook_deploy_key')) {
        invoke('deploy:key');
    }
});
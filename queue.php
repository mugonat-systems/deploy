<?php

namespace Deployer;

use RuntimeException;

require_once 'env.php';
require_once 'supervisor.php';

set('queue_workers', envGet('QUEUE_WORKERS', 1));
set('queue_connection', envGet('QUEUE_CONNECTION', 'database'));

desc('Get status of Queue workers');
task('queue:status', function () {
    writeln('<info>Checking queue workers status...</info>');
    supervisorStatus('queue:work');
});

desc('Configure Queue when not configured');
task('queue:setup', function () {
    // Validate environment first
    invoke('supervisor:validate');

    $host = str_replace('.', '', get('hostname'));
    $source = getcwd() . '/.queue';
    $compiled = getcwd() . "/.queue-$host.conf";

    // Validate source file exists
    if (!file_exists($source)) {
        $source = __DIR__ . '/resources/.queue';
    }

    if (!file_exists($source)) {
        throw new RuntimeException("Queue template file not found: $source");
    }

    $supervisorConfig = "/etc/supervisor/conf.d/$host-queue.conf";

    writeln("Checking $supervisorConfig");

    // Check if config already exists
    if (supervisorConfigExists($supervisorConfig) && !askConfirmation('Supervisor queue config exists, do you want to replace it?', false)) {
        writeln('⚠️ Queue is already configured for ' . currentHost());
        // Clean up compiled file
        @unlink($compiled);
        return;
    }

    // Compile the configuration
    compileSupervisorConfig($source, $compiled, [
        '{{bin/php}}' => currentHost()->get('bin/php'),
        '{{current_path}}' => currentHost()->get('current_path'),
        '{{queue_workers}}' => currentHost()->get('queue_workers'),
        '{{queue_connection}}' => currentHost()->get('queue_connection'),
        '{{hostname}}' => $host,
        '{{deploy_user}}' => currentHost()->get('remote_user', 'deploy'),
    ]);

    invoke('queue:configure');
    invoke('queue:status');

    // Clean up compiled file after deployment
    @unlink($compiled);
});

desc('Configure Queue service');
task('queue:configure', function () {
    $host = str_replace('.', '', get('hostname'));

    writeln('Configuring Queue workers for ' . currentHost());

    $filename = "$host-queue.conf";
    $compiled = getcwd() . "/.queue-$host.conf";

    try {
        deploySupervisorConfig($compiled, "$host-queue-worker", $filename);
        writeln("✅ Queue workers configured successfully for $host");

        supervisorStatus('queue:work');
    } catch (\Exception $e) {
        writeln("⚠️ Failed to configure Queue workers: " . $e->getMessage());
        throw $e;
    }
});

desc('Configure Queue environment variables');
task('queue:env', function () {
    // Get the queue connection
    $connection = get('queue_connection', 'redis');
    $workers = get('queue_workers', 1);

    writeln('');
    writeln('<comment>Current configuration:</comment>');
    writeln("  Queue Connection: $connection");
    writeln("  Number of Workers: $workers");
    writeln('');

    // Ask for queue connection
    $connection = ask('Enter queue connection (redis/database/sync):', $connection);

    if (empty($connection)) {
        throw new RuntimeException('Queue connection is required');
    }

    // Confirm settings
    writeln('');
    writeln('<comment>Configuration to be added:</comment>');
    writeln("  QUEUE_CONNECTION=$connection");
    writeln('');

    if (!askConfirmation('Do you want to proceed?', true)) {
        writeln('Setup cancelled.');
        return;
    }

    // Update the deployment config
    set('queue_connection', $connection);
    set('queue_workers', (int)$workers);

    $envPath = '{{deploy_path}}/shared/.env';

    // Remove existing Queue configuration if present
    run("sed -i '/^QUEUE_CONNECTION=/d' $envPath");

    // Append new configuration
    $config = <<<EOT

# Queue Configuration
QUEUE_CONNECTION=$connection
EOT;

    run("echo '$config' >> $envPath");
    writeln('✅ .env file updated with Queue configuration');

    writeln('');
    writeln('<info>Queue environment configuration completed!</info>');
});

desc('Interactive setup for Queue workers');
task('queue', function () {
    invoke('artisan:optimize:clear');

    writeln('<info>Starting interactive Queue workers setup...</info>');

    invoke('env:backup');
    invoke('queue:env');
    invoke('queue:setup');
    invoke('artisan:optimize');

    writeln('');
    writeln('<info>Queue workers setup completed!</info>');
});
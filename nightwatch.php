<?php

namespace Deployer;

use Exception;
use RuntimeException;

set('nightwatch_port', envGet('NIGHTWATCH_PORT', 2048));
set('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');

desc('Find next available port starting from 2048');
task('nightwatch:find-port', function () {
    $startPort = 2048;
    $maxPort = 3048; // Check up to 1000 ports

    writeln("Searching for available port starting from $startPort...");

    for ($port = $startPort; $port <= $maxPort; $port++) {
        // Check if port is in use using netstat or ss
        $inUse = test("netstat -tuln 2>/dev/null | grep -q ':$port ' || ss -tuln 2>/dev/null | grep -q ':$port '");

        if (!$inUse) {
            writeln("✅ Found available port: $port");
            set('nightwatch_port', $port);
            return $port;
        }
    }

    throw new RuntimeException("No available ports found between $startPort and $maxPort");
});

desc('Validate Nightwatch environment');
task('nightwatch:validate', function () {
    writeln('Validating Nightwatch environment...');

    // Check if supervisor is running
    if (!test('pgrep supervisord > /dev/null')) {
        throw new RuntimeException('Supervisor is installed but not running. Start it with: sudo systemctl start supervisor');
    }

    // Check if deploy script exists
    $scriptPath = get('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');
    if (!test("[ -f $scriptPath ]")) {
        throw new RuntimeException("Deploy script not found: $scriptPath. Install the wrapper script first.");
    }

    // Check if script is executable
    if (!test("[ -x $scriptPath ]")) {
        throw new RuntimeException("Deploy script is not executable: $scriptPath");
    }

    // Check supervisor config directory is writable by root
    if (!test('[ -d /etc/supervisor/conf.d ]')) {
        throw new RuntimeException('Supervisor config directory not found: /etc/supervisor/conf.d');
    }

    writeln('✅ Environment validated successfully');
});

desc('Configure Nightwatch when not configured');
task('nightwatch:setup', function () {
    // Validate environment first
    invoke('nightwatch:validate');

    $host = str_replace('.', '', get('hostname'));
    $source = getcwd() . '/.nightwatch';
    $compiled = getcwd() . "/.nightwatch-$host.conf";

    // Validate source file exists
    if (!file_exists($source)) {
        $source = __DIR__ . '/resources/.nightwatch';
    }

    $config = currentHost()->config();
    $replacements = [
        '{{bin/php}}' => currentHost()->get('bin/php'),
        '{{current_path}}' => currentHost()->get('current_path'),
        '{{port}}' => currentHost()->get('nightwatch_port'),
        '{{hostname}}' => $host,
    ];

    foreach ($config->ownValues() as $name => $value) {
        $replacements['{{' . $name . '}}'] = $replacements['{{' . $name . '}}'] ?? $value;
    }

    $contents = strtr(file_get_contents($source), $replacements);

    // Ensure compiled file is created
    if (file_put_contents($compiled, $contents) === false) {
        writeln("⚠️ Failed to create compiled config: $compiled");
        return;
    }

    $supervisor = "/etc/supervisor/conf.d/$host.conf";

    writeln("Checking $supervisor");

    // Fixed: test for file (-f) not directory (-d)
    if (test("[ -f $supervisor ]") && !askConfirmation('Supervisor config exists, do you want to replace it?', false)) {
        writeln('⚠️ Nightwatch is already configured for ' . currentHost());
        // Clean up compiled file
        @unlink($compiled);
        return;
    }

    invoke('nightwatch:configure');
    invoke('nightwatch:status');

    // Clean up compiled file after deployment
    @unlink($compiled);
});

desc('Configure Nightwatch service');
task('nightwatch:configure', function () {
    $host = str_replace('.', '', get('hostname'));

    writeln('Configuring Nightwatch for ' . currentHost());

    $filename = "$host.conf";
    $compiled = getcwd() . "/.nightwatch-$filename";

    // Validate compiled file exists before upload
    if (!file_exists($compiled)) {
        writeln("⚠️ Compiled config not found: $compiled");
        return;
    }

    // Upload to a temporary location in deploy path
    $remotePath = "{{deploy_path}}/$filename";
    upload($compiled, $remotePath);

    // Use the wrapper script (no password needed with NOPASSWD in sudoers)
    $scriptPath = get('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');

    try {
        run("sudo $scriptPath $remotePath $host-nightwatch-agent");
        writeln("✅ Nightwatch configured successfully for $host");

        // Verify service is running
        $status = run("sudo supervisorctl status $host-nightwatch-agent || echo 'NOT_RUNNING'");
        if (str_contains($status, 'RUNNING')) {
            writeln("✅ Nightwatch agent is running");
        } else {
            writeln("⚠️ Nightwatch agent status: $status");
        }
    } catch (Exception $e) {
        writeln("⚠️ Failed to configure Nightwatch: " . $e->getMessage());
        // Clean up uploaded file on failure
        run("rm -f $remotePath");
        throw $e;
    }
});

desc('Get status of Nightwatch agent');
task('nightwatch:status', function () {
    cd('{{current_path}}');
    run('{{bin/php}} artisan nightwatch:status');
});

desc('Interactive setup for Nightwatch');
task('nightwatch', function () {
    invoke('artisan:optimize:clear');

    writeln('<info>Starting interactive Nightwatch setup...</info>');

    // Find available port
    invoke('nightwatch:find-port');
    $port = get('nightwatch_port');

    // Ask for Nightwatch token
    $token = ask('Enter your Nightwatch token:');

    if (empty($token)) {
        throw new RuntimeException('Nightwatch token is required');
    }

    // Confirm settings
    writeln('');
    writeln('<comment>Configuration to be added:</comment>');
    writeln("  NIGHTWATCH_TOKEN=$token");
    writeln("  NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1");
    writeln("  NIGHTWATCH_INGEST_URI=127.0.0.1:$port");
    writeln('');

    if (!askConfirmation('Do you want to proceed?', true)) {
        writeln('Setup cancelled.');
        return;
    }

    // Backup and update .env file
    $envPath = '{{deploy_path}}/shared/.env';

    writeln('Creating backup of .env file...');
    run("cp $envPath $envPath.backup");
    writeln('✅ Backup created: .env.backup');

    // Remove existing Nightwatch configuration if present
    run("sed -i '/^NIGHTWATCH_TOKEN=/d' $envPath");
    run("sed -i '/^NIGHTWATCH_REQUEST_SAMPLE_RATE=/d' $envPath");
    run("sed -i '/^NIGHTWATCH_INGEST_URI=/d' $envPath");

    // Append new configuration
    $config = <<<EOT

# Nightwatch Configuration
NIGHTWATCH_TOKEN=$token
NIGHTWATCH_REQUEST_SAMPLE_RATE=0.1
NIGHTWATCH_INGEST_URI=127.0.0.1:$port
EOT;

    run("echo '$config' >> $envPath");
    writeln('✅ .env file updated with Nightwatch configuration');

    // Store port for later use
    set('nightwatch_port', $port);

    writeln('');
    writeln('<info>Nightwatch setup completed successfully!</info>');
    writeln('');

    // Configure Nightwatch
    invoke('nightwatch:setup');
    invoke('artisan:optimize');
});
<?php

namespace Deployer;

use Exception;
use RuntimeException;

set('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');
set('nightwatch_port', 2048);

desc('Validate Nightwatch environment');
task('deploy:nightwatch:validate', function () {
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
task('deploy:nightwatch', function () use ($port) {
    // Validate environment first
    invoke('deploy:nightwatch:validate');

    $host = str_replace('.', '', get('hostname'));
    $source = __DIR__ . '/.nightwatch';
    $compiled = __DIR__ . "/.nightwatch-$host.conf";

    // Validate source file exists
    if (!file_exists($source)) {
        writeln("⚠️ Nightwatch template not found: $source");
        return;
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

    invoke('deploy:nightwatch:configure');

    // Clean up compiled file after deployment
    @unlink($compiled);
});

desc('Configure Nightwatch service');
task('deploy:nightwatch:configure', function () {
    $host = str_replace('.', '', get('hostname'));

    writeln('Configuring Nightwatch for ' . currentHost());

    $filename = "$host.conf";
    $compiled = __DIR__ . "/.nightwatch-$filename";

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
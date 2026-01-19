<?php

namespace Deployer;

use Deployer\Exception\RunException;
use RuntimeException;
use Throwable;

set('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');

desc('Validate Supervisor environment');
task('supervisor:validate', function () {
    writeln('Validating Supervisor environment...');

    // Check if supervisor is running
    if (!test('pgrep supervisord > /dev/null')) {
        throw new RuntimeException('Supervisor is installed but not running. Contact your system administrator to start it.');
    }

    // Check if deploy script exists
    $scriptPath = get('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');
    if (!test("[ -f $scriptPath ]")) {
        throw new RuntimeException("Deploy script not found: $scriptPath. Contact your system administrator to install the wrapper script.");
    }

    // Check if script is executable
    if (!test("[ -x $scriptPath ]")) {
        throw new RuntimeException("Deploy script is not executable: $scriptPath");
    }

    // Check supervisor config directory exists
    if (!test('[ -d /etc/supervisor/conf.d ]')) {
        throw new RuntimeException('Supervisor config directory not found: /etc/supervisor/conf.d');
    }

    writeln('✅ Environment validated successfully');
});

/**
 * Deploy a supervisor configuration file
 *
 * @param string $localConfigPath - Path to local compiled config file
 * @param string $programName - Name of the supervisor program
 * @param string $filename - Filename for the config (without path)
 * @return void
 * @throws RunException
 */
function deploySupervisorConfig(string $localConfigPath, string $programName, string $filename): void
{
    if (!file_exists($localConfigPath)) {
        throw new RuntimeException("Compiled config not found: $localConfigPath");
    }

    // Upload to a temporary location in deploy path
    $remotePath = "{{deploy_path}}/$filename";
    upload($localConfigPath, $remotePath);

    // Use the wrapper script (no password needed with NOPASSWD in sudoers)
    $scriptPath = get('supervisor_deploy_script', '/usr/local/bin/deploy-supervisor-config');

    try {
        run("echo '' | sudo $scriptPath $remotePath $programName");
        writeln("✅ Supervisor config deployed successfully: $programName");
    } catch (Throwable $e) {
        // Clean up uploaded file on failure
//        run("rm -f $remotePath");
        throw $e;
    }

    // Clean up uploaded file after successful deployment
//    run("rm -f $remotePath");
}

/**
 * Compile a supervisor configuration template
 *
 * @param string $templatePath - Path to template file
 * @param string $outputPath - Path for compiled output
 * @param array $replacements - Key-value pairs for template replacement
 * @return void
 */
function compileSupervisorConfig(string $templatePath, string $outputPath, array $replacements): void
{
    if (!file_exists($templatePath)) {
        throw new RuntimeException("Template file not found: $templatePath");
    }

    $config = currentHost()->config();

    foreach ($config->ownValues() as $name => $value) {
        $replacements['{{' . $name . '}}'] = $replacements['{{' . $name . '}}'] ?? $value;
    }

    $contents = strtr(file_get_contents($templatePath), $replacements);

    if (file_put_contents($outputPath, $contents) === false) {
        throw new RuntimeException("Failed to create compiled config: $outputPath");
    }

    writeln("✅ Config compiled: $outputPath");
}

/**
 * Check if supervisor config file exists
 *
 * @param string $configPath - Full path to config file in /etc/supervisor/conf.d/
 * @return bool
 */
function supervisorConfigExists(string $configPath): bool
{
    return test("[ -f $configPath ]");
}

/**
 * Get status of a supervisor program
 *
 * @param string $program - Name of the supervisor program (can include wildcards)
 * @return void
 */
function supervisorStatus(string $program): void
{
    $status = run("ps -f -u {{remote_user}} | grep {{hostname}}");

    if (str_contains($status, $program)) {
        writeln("✅ {{hostname}} $program is running");
    } else {
        writeln("⚠️ {{hostname}} $program is not running");
    }
}
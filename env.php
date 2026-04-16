<?php

namespace Deployer;

/**
 * Reads .env file and returns value for the given key
 *
 * @param string $name The environment variable name
 * @param mixed|null $default Default value if key doesn't exist
 */
function envGet(string $name, mixed $default = null)
{
    $envPath = getcwd() . '/.env.deployer';

    // Check if .env exists
    if (!file_exists($envPath)) {
        return $default;
    }

    $prefixedName = "DEPLOYER_$name";
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments and invalid lines
        if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if ($key === $prefixedName || $key === $name) {
            $value = trim($value);

            // Remove surrounding quotes
            if (preg_match('/^["\'].*["\']$/', $value)) {
                $value = substr($value, 1, -1);
            }

            // Convert special values
            return match (strtolower($value)) {
                'true' => true,
                'false' => false,
                'null' => null,
                'empty' => '',
                default => $value,
            };
        }
    }

    return $default;
}

desc('Backup .env file on the host');
task('env:backup', function () {
    $envFile = '{{release_or_current_path}}/.env';
    $backupFile = '{{release_or_current_path}}/.env.backup-' . date('Y-m-d-H-i-s');
    if (test("[ -f $envFile ]")) {
        run("cp $envFile $backupFile");
        writeln("Backup created at $backupFile");
    } else {
        writeln("No .env file found to backup.");
    }
});

desc('Update .env variable on the host');
task('env:update', function () {
    invoke('env:backup');

    $envFile = '{{release_or_current_path}}/.env';
    if (!test("[ -f $envFile ]")) {
        writeln("No .env file found on the host.");
        return;
    }

    $content = run("cat $envFile");
    $lines = explode("\n", $content);
    $vars = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, '#')) {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value);
        }
    }

    $choices = array_keys($vars);
    $choices[] = '[Add New Variable]';

    $selectedKey = askChoice('Select variable to update or add', $choices);

    if ($selectedKey === '[Add New Variable]') {
        $localVars = [];
        $localEnvExample = getcwd() . '/.env.example';
        if (file_exists($localEnvExample)) {
            $exampleContent = file_get_contents($localEnvExample);
            $exampleLines = explode("\n", $exampleContent);
            foreach ($exampleLines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) {
                    continue;
                }
                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $localVars[] = trim($key);
                }
            }
        }

        // Also try to get from local .env if example didn't have much
        $localEnv = getcwd() . '/.env';
        if (file_exists($localEnv)) {
            $envLines = file($localEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($envLines as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $localVars[] = trim($key);
            }
        }

        $localVars = array_unique($localVars);
        sort($localVars);

        $newKeyChoices = array_values(array_filter($localVars, fn($k) => !isset($vars[$k])));
        $newKeyChoices[] = '[Enter Custom Name]';

        $selectedKey = askChoice('Select variable name to add', $newKeyChoices);

        if ($selectedKey === '[Enter Custom Name]') {
            $selectedKey = ask('Enter new variable name');
        }

        if (empty($selectedKey)) {
            writeln("No name provided. Aborting.");
            return;
        }

        if (isset($vars[$selectedKey])) {
            writeln("Variable $selectedKey already exists. Use update instead.");
            $currentValue = $vars[$selectedKey];
        } else {
            $currentValue = '';
        }
    } else {
        $currentValue = $vars[$selectedKey];
    }

    $newValue = ask("New value for $selectedKey (Current: $currentValue)", $currentValue);

    if ($newValue === $currentValue && isset($vars[$selectedKey])) {
        writeln("No changes made.");
    } else {
        // Wrap the value in quotes if it contains spaces or special characters
        if (preg_match('/\s/', $newValue) && !preg_match('/^["\'].*["\']$/', $newValue)) {
            $newValue = '"' . $newValue . '"';
        }

        // We'll escape $newValue for use in sed
        $escapedValue = str_replace(["\\", "/", "&"], ["\\\\", "\\/", "\\&"], $newValue);
        $escapedKey = str_replace(["\\", "/", "&"], ["\\\\", "\\/", "\\&"], $selectedKey);

        if (isset($vars[$selectedKey])) {
            run("sed -i 's/^$escapedKey=.*/$escapedKey=$escapedValue/' $envFile");
            writeln("Updated $selectedKey to $newValue");
        } else {
            run("echo '$selectedKey=$newValue' >> $envFile");
            writeln("Added $selectedKey with value $newValue");
        }
    }

    invoke('artisan:optimize');
});
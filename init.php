<?php

namespace Deployer;

set('keep_releases', 2);

task('env:init', function () {
    $exampleFile = getcwd() . '/.env.deployer.example';

    if (!file_exists($exampleFile)) {
        copy(__DIR__ . '/resources/.env.deployer.example', $exampleFile);

        writeln("<info>Created file $exampleFile</info>");
    }

    $file = getcwd() . '/.env.deployer';

    if (!file_exists($file)) {
        copy($exampleFile, $file);

        writeln("<info>Created file $file</info>");
    }
});

task('nightwatch:init', function () {
    $file = getcwd() . '/.nightwatch';

    if (!file_exists($file)) {
        copy(__DIR__ . '/resources/.nightwatch', $file);

        writeln("<info>Created file $file</info>");
    }
});

task('utils:init', function () {
    invoke('env:init');
    invoke('nightwatch:init');
});
<?php

declare(strict_types=1);

test('all source files declare strict types')
    ->expect('Mindtwo\Monitoring\WordPress')
    ->toUseStrictTypes();

test('no debug or dangerous shell helpers are used')
    ->expect('Mindtwo\Monitoring\WordPress')
    ->not->toUse(['dd', 'dump', 'var_dump', 'ray', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval']);

test('the plugin never couples to laravel')
    ->expect('Mindtwo\Monitoring\WordPress')
    ->not->toUse(['Illuminate', 'Laravel']);

<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\WordPress\NativeWordPressApi;

afterEach(function () {
    unset($_ENV['MONITORING_TEST_VALUE'], $_SERVER['MONITORING_TEST_VALUE']);
    putenv('MONITORING_TEST_VALUE');
});

test('env skips an empty $_ENV entry and reads the next source', function () {
    $_ENV['MONITORING_TEST_VALUE'] = '';
    $_SERVER['MONITORING_TEST_VALUE'] = 'from-server';

    expect((new NativeWordPressApi)->env('MONITORING_TEST_VALUE'))->toBe('from-server');
});

test('env falls back to getenv when the superglobals hold nothing', function () {
    $_ENV['MONITORING_TEST_VALUE'] = '';
    putenv('MONITORING_TEST_VALUE=from-getenv');

    expect((new NativeWordPressApi)->env('MONITORING_TEST_VALUE'))->toBe('from-getenv');
});

test('env is null when no source has a value', function () {
    $_ENV['MONITORING_TEST_VALUE'] = '';

    expect((new NativeWordPressApi)->env('MONITORING_TEST_VALUE'))->toBeNull();
});

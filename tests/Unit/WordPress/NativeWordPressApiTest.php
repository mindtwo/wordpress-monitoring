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

test('remotePost sends through wp_remote_post without redirects and with TLS verification', function () {
    require_once __DIR__.'/../../Support/http-functions.php';
    $GLOBALS['m2_wp_remote_post_calls'] = [];
    $GLOBALS['m2_wp_remote_post'] = ['response' => ['code' => 202], 'body' => '{"status":"accepted"}'];

    $result = (new NativeWordPressApi)->remotePost('https://monitoring.example/api/monitoring', ['X-Test' => '1'], '{"a":1}', 9);

    $call = $GLOBALS['m2_wp_remote_post_calls'][0];

    expect($result)->toBe(['status' => 202, 'body' => '{"status":"accepted"}', 'error' => null])
        ->and($call['url'])->toBe('https://monitoring.example/api/monitoring')
        ->and($call['args']['redirection'])->toBe(0)
        ->and($call['args']['sslverify'])->toBeTrue()
        ->and($call['args']['timeout'])->toBe(9)
        ->and($call['args']['headers'])->toBe(['X-Test' => '1'])
        ->and($call['args']['body'])->toBe('{"a":1}');
});

test('remotePost returns an error status as is and a WP_Error as transport error', function () {
    require_once __DIR__.'/../../Support/http-functions.php';

    $GLOBALS['m2_wp_remote_post'] = ['response' => ['code' => 301], 'body' => 'moved'];
    $redirect = (new NativeWordPressApi)->remotePost('https://monitoring.example/x', [], '{}', 5);

    $GLOBALS['m2_wp_remote_post'] = new WP_Error('local_guard_blocked', 'Local Guard blocked the request to monitoring.example.');
    $blocked = (new NativeWordPressApi)->remotePost('https://monitoring.example/x', [], '{}', 5);

    expect($redirect)->toBe(['status' => 301, 'body' => 'moved', 'error' => null])
        ->and($blocked)->toBe(['status' => 0, 'body' => '', 'error' => 'Local Guard blocked the request to monitoring.example.']);
});

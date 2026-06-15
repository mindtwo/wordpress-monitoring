<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Http\PullEndpoint;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

function pullEndpoint(FakeWordPressApi $wordPress, ?callable $snapshot = null): PullEndpoint
{
    return new PullEndpoint(
        $wordPress,
        $snapshot ?? static fn (): array => ['schema_version' => '1.0', 'collected_at' => 'now']
    );
}

test('a correctly signed request receives the snapshot', function () {
    [$status, $payload] = pullEndpoint(fakeWordPress())->respond('203.0.113.10', signedPullHeaders());

    expect($status)->toBe(200)
        ->and($payload['schema_version'])->toBe('1.0');
});

test('invalid signatures are rejected', function () {
    $headers = signedPullHeaders();
    $headers['X-Monitoring-Signature'] = str_repeat('0', 64);

    [$status] = pullEndpoint(fakeWordPress())->respond('203.0.113.10', $headers);

    expect($status)->toBe(401);
});

test('expired signatures are rejected', function () {
    [$status] = pullEndpoint(fakeWordPress())->respond('203.0.113.10', signedPullHeaders(timestamp: time() - 301));

    expect($status)->toBe(401);
});

test('a disabled route answers 404', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['route_enabled'] = false;

    [$status] = pullEndpoint($wordPress)->respond('203.0.113.10', signedPullHeaders());

    expect($status)->toBe(404);
});

test('unconfigured credentials answer 503', function () {
    [$status] = pullEndpoint(new FakeWordPressApi)->respond('203.0.113.10', signedPullHeaders());

    expect($status)->toBe(503);
});

test('the ip allow-list is enforced', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['ip_allow_list'] = '10.0.0.0/8';

    [$denied] = pullEndpoint($wordPress)->respond('203.0.113.10', signedPullHeaders());
    [$allowed] = pullEndpoint($wordPress)->respond('10.4.5.6', signedPullHeaders());

    expect($denied)->toBe(403)
        ->and($allowed)->toBe(200);
});

test('requests are rate limited per ip via transients', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['rate_limit_per_minute'] = 2;

    $endpoint = pullEndpoint($wordPress);

    [$first] = $endpoint->respond('203.0.113.10', signedPullHeaders());
    [$second] = $endpoint->respond('203.0.113.10', signedPullHeaders());
    [$third] = $endpoint->respond('203.0.113.10', signedPullHeaders());

    expect($first)->toBe(200)
        ->and($second)->toBe(200)
        ->and($third)->toBe(429);
});

test('snapshots are cached in a transient for the configured window', function () {
    $wordPress = fakeWordPress();
    $builds = 0;

    $endpoint = pullEndpoint($wordPress, function () use (&$builds): array {
        $builds++;

        return ['schema_version' => '1.0', 'build' => $builds];
    });

    [, $first] = $endpoint->respond('203.0.113.10', signedPullHeaders());
    [, $second] = $endpoint->respond('203.0.113.10', signedPullHeaders());

    expect($builds)->toBe(1)
        ->and($first)->toBe($second)
        ->and($wordPress->transients)->toHaveKey(PullEndpoint::CACHE_TRANSIENT);
});

test('caching can be disabled', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['cache_seconds'] = 0;
    $builds = 0;

    $endpoint = pullEndpoint($wordPress, function () use (&$builds): array {
        $builds++;

        return ['build' => $builds];
    });

    $endpoint->respond('203.0.113.10', signedPullHeaders());
    $endpoint->respond('203.0.113.10', signedPullHeaders());

    expect($builds)->toBe(2)
        ->and($wordPress->transients)->not->toHaveKey(PullEndpoint::CACHE_TRANSIENT);
});

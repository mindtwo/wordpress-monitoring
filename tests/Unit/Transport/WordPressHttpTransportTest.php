<?php

declare(strict_types=1);

use Mindtwo\Monitoring\Data\Credentials;
use Mindtwo\Monitoring\Data\Snapshot;
use Mindtwo\Monitoring\SnapshotFactory;
use Mindtwo\Monitoring\Transport\HmacRequestSigner;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;
use Mindtwo\Monitoring\WordPress\Transport\WordPressHttpTransport;

function transportSnapshot(): Snapshot
{
    return (new SnapshotFactory(null, 'production', 'prj_test'))->make();
}

function wordPressTransport(FakeWordPressApi $wordPress, string $endpoint = 'https://monitoring.example/api/monitoring', ?Credentials $credentials = null): WordPressHttpTransport
{
    return new WordPressHttpTransport($wordPress, $endpoint, $credentials ?? new Credentials('prj_test', 'test-secret'), new HmacRequestSigner, 7);
}

test('sends the signed snapshot through the WordPress HTTP API', function () {
    // Through wp_remote_post the request passes pre_http_request, so a local-guard
    // must-use plugin can stop a local copy from reporting to the live target.
    $wordPress = new FakeWordPressApi;
    $wordPress->remotePostResponses['https://monitoring.example/api/monitoring'] = ['status' => 202, 'body' => '{"status":"accepted"}', 'error' => null];
    $snapshot = transportSnapshot();

    $result = wordPressTransport($wordPress)->send($snapshot);

    $request = $wordPress->remotePostRequests[0];

    expect($result->success)->toBeTrue()
        ->and($result->statusCode)->toBe(202)
        ->and($request['url'])->toBe('https://monitoring.example/api/monitoring')
        ->and($request['body'])->toBe($snapshot->toJson())
        ->and($request['timeout'])->toBe(7)
        ->and($request['headers']['Content-Type'])->toBe('application/json')
        ->and($request['headers'][HmacRequestSigner::HEADER_KEY])->toBe('prj_test')
        ->and($request['headers'][HmacRequestSigner::HEADER_SIGNATURE])->toBe(
            HmacRequestSigner::sign($snapshot->toJson(), $request['headers'][HmacRequestSigner::HEADER_TIMESTAMP], 'test-secret')
        );
});

test('reports a blocked or failed request as a failure', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->remotePostResponses['https://monitoring.example/api/monitoring'] = ['status' => 0, 'body' => '', 'error' => 'Local Guard blocked the request to monitoring.example.'];

    $result = wordPressTransport($wordPress)->send(transportSnapshot());

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('Local Guard blocked the request to monitoring.example.');
});

test('reports an error status with a short summary of the body', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->remotePostResponses['https://monitoring.example/api/monitoring'] = ['status' => 422, 'body' => '{"message":"Snapshot from a local installation rejected"}', 'error' => null];

    $result = wordPressTransport($wordPress)->send(transportSnapshot());

    expect($result->success)->toBeFalse()
        ->and($result->statusCode)->toBe(422)
        ->and($result->error)->toContain('HTTP 422')
        ->and($result->error)->toContain('Snapshot from a local installation rejected');
});

test('sends nothing without complete credentials or with a non-http endpoint', function () {
    $wordPress = new FakeWordPressApi;

    $incomplete = wordPressTransport($wordPress, credentials: new Credentials('', ''))->send(transportSnapshot());
    $badScheme = wordPressTransport($wordPress, 'file:///etc/passwd')->send(transportSnapshot());

    expect($incomplete->success)->toBeFalse()
        ->and($incomplete->error)->toBe('Monitoring credentials are not configured.')
        ->and($badScheme->success)->toBeFalse()
        ->and($badScheme->error)->toContain('only http(s) URLs')
        ->and($wordPress->remotePostRequests)->toBe([]);
});

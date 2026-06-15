<?php

declare(strict_types=1);

use Mindtwo\Monitoring\Data\Credentials;
use Mindtwo\Monitoring\Transport\HmacRequestSigner;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

function fakeWordPress(): FakeWordPressApi
{
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = [
        'project_key' => 'prj_test',
        'secret' => 'test-secret',
    ];

    return $wordPress;
}

/**
 * @return array<string, string>
 */
function signedPullHeaders(string $body = '', ?int $timestamp = null): array
{
    $signer = new HmacRequestSigner($timestamp !== null ? static fn (): int => $timestamp : null);

    return $signer->headers($body, new Credentials('prj_test', 'test-secret'));
}

<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Transport;

use Mindtwo\Monitoring\Contracts\RequestSigner;
use Mindtwo\Monitoring\Contracts\Transport;
use Mindtwo\Monitoring\Data\Credentials;
use Mindtwo\Monitoring\Data\Snapshot;
use Mindtwo\Monitoring\Data\TransportResult;
use Mindtwo\Monitoring\Support\InstalledVersion;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;
use Throwable;

/**
 * Sends the signed snapshot through the WordPress HTTP API (wp_remote_post)
 * instead of base-monitoring's curl transport. The request then passes
 * WordPress' pre_http_request filter like any other outgoing request, so a
 * must-use plugin that blocks requests on local copies (the project
 * template's local-guard.php) also stops a pulled production database from
 * reporting to the live target. TLS is verified and redirects are not followed.
 */
final class WordPressHttpTransport implements Transport
{
    public function __construct(
        private WordPressApi $wordPress,
        private string $endpoint,
        private Credentials $credentials,
        private RequestSigner $signer,
        private int $timeoutSeconds = 10
    ) {}

    public function send(Snapshot $snapshot): TransportResult
    {
        $scheme = strtolower((string) parse_url($this->endpoint, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return TransportResult::failed(sprintf('Invalid monitoring endpoint "%s" — only http(s) URLs are supported.', $this->endpoint));
        }

        if (! $this->credentials->isComplete()) {
            return TransportResult::failed('Monitoring credentials are not configured.');
        }

        try {
            $payload = $snapshot->toJson();
        } catch (Throwable $exception) {
            return TransportResult::failed('Unable to encode the snapshot: '.$exception->getMessage());
        }

        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'mindtwo-monitoring/'.InstalledVersion::of('mindtwo/base-monitoring'),
            ],
            $this->signer->headers($payload, $this->credentials)
        );

        $response = $this->wordPress->remotePost($this->endpoint, $headers, $payload, $this->timeoutSeconds);

        if ($response['error'] !== null) {
            return TransportResult::failed($response['error']);
        }

        $status = $response['status'];

        if ($status >= 200 && $status < 300) {
            return TransportResult::delivered($status);
        }

        $summary = trim(mb_substr(strip_tags($response['body']), 0, 300));

        return TransportResult::failed(
            sprintf('The monitoring endpoint responded with HTTP %d%s.', $status, $summary !== '' ? ': '.$summary : ''),
            $status
        );
    }
}

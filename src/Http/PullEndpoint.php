<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Http;

use Mindtwo\Monitoring\Http\PullRequestHandler;
use Mindtwo\Monitoring\Support\FixedWindowRateLimiter;
use Mindtwo\Monitoring\Transport\HmacSignatureVerifier;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Pure request logic of the /api/app-monitoring endpoint: rate limiting via
 * transients, IP allow-list, signature verification (through the shared
 * PullRequestHandler) and short-lived snapshot caching. The WordPress glue
 * only maps superglobals in and emits the result.
 */
final class PullEndpoint
{
    public const CACHE_TRANSIENT = 'mindtwo_monitoring_snapshot';

    private WordPressConfigurationRepository $config;

    /** @var callable(): array<string, mixed> */
    private $snapshot;

    /**
     * @param  callable(): array<string, mixed>  $snapshot  builds a fresh snapshot payload
     */
    public function __construct(
        private WordPressApi $wordPress,
        callable $snapshot,
        ?WordPressConfigurationRepository $config = null
    ) {
        $this->config = $config ?? new WordPressConfigurationRepository($wordPress);
        $this->snapshot = $snapshot;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int, 1: array<string, mixed>} [HTTP status code, JSON payload]
     */
    public function respond(string $ip, array $headers, string $body = ''): array
    {
        if (! $this->config->routeEnabled()) {
            return [404, ['message' => 'Not found.']];
        }

        $handler = new PullRequestHandler(
            $this->config,
            new HmacSignatureVerifier(max(0, $this->config->integer('signature_tolerance'))),
            $this->rateLimiter()
        );

        return $handler->handle($ip, $headers, $body, function (): array {
            return $this->cachedSnapshot();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedSnapshot(): array
    {
        $seconds = max(0, $this->config->integer('cache_seconds'));

        if ($seconds > 0) {
            $cached = $this->wordPress->transient(self::CACHE_TRANSIENT);

            if (is_array($cached) && $cached !== []) {
                /** @var array<string, mixed> $cached */
                return $cached;
            }
        }

        $payload = ($this->snapshot)();

        if ($seconds > 0) {
            $this->wordPress->setTransient(self::CACHE_TRANSIENT, $payload, $seconds);
        }

        return $payload;
    }

    private function rateLimiter(): FixedWindowRateLimiter
    {
        return new FixedWindowRateLimiter(
            fn (string $key) => $this->wordPress->transient($key),
            function (string $key, $value, int $ttl): void {
                $this->wordPress->setTransient($key, $value, $ttl);
            },
            max(1, $this->config->integer('rate_limit_per_minute')),
            60
        );
    }
}

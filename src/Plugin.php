<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress;

use Mindtwo\Monitoring\Monitor;
use Mindtwo\Monitoring\WordPress\Admin\SettingsPage;
use Mindtwo\Monitoring\WordPress\Http\PullEndpoint;
use Mindtwo\Monitoring\WordPress\Scheduler\PushScheduler;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\NativeWordPressApi;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * WordPress glue: registers hooks, the rewrite-based pull endpoint, the cron
 * push and the settings page. All decision logic lives in the unit-tested
 * classes this one wires together.
 */
final class Plugin
{
    public const QUERY_VAR = 'mindtwo_monitoring';

    public const ROUTE = 'api/app-monitoring';

    private static ?Monitor $monitor = null;

    public static function boot(string $pluginFile): void
    {
        $wordPress = new NativeWordPressApi;
        $config = new WordPressConfigurationRepository($wordPress);
        $scheduler = new PushScheduler($wordPress, static fn () => self::monitor($wordPress)->push(), $config);

        add_action('init', static function () use ($scheduler): void {
            add_rewrite_rule('^'.self::ROUTE.'/?$', 'index.php?'.self::QUERY_VAR.'=1', 'top');
            $scheduler->sync();
        });

        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QUERY_VAR;

            return $vars;
        });

        add_action('template_redirect', static function () use ($wordPress, $config): void {
            if ((string) get_query_var(self::QUERY_VAR) !== '1') {
                return;
            }

            self::respondToPullRequest($wordPress, $config);
        });

        add_action(PushScheduler::HOOK, static function () use ($scheduler): void {
            $scheduler->run();
        });

        if (is_admin()) {
            (new SettingsPage($wordPress))->register();
        }

        register_activation_hook($pluginFile, static function () use ($scheduler): void {
            add_rewrite_rule('^'.self::ROUTE.'/?$', 'index.php?'.self::QUERY_VAR.'=1', 'top');
            flush_rewrite_rules();
            $scheduler->sync();
        });

        register_deactivation_hook($pluginFile, static function () use ($scheduler, $wordPress): void {
            $scheduler->clear();
            $wordPress->deleteTransient(PullEndpoint::CACHE_TRANSIENT);
            flush_rewrite_rules();
        });
    }

    public static function monitor(?WordPressApi $wordPress = null): Monitor
    {
        return self::$monitor ??= MonitorFactory::make($wordPress ?? new NativeWordPressApi);
    }

    private static function respondToPullRequest(WordPressApi $wordPress, WordPressConfigurationRepository $config): void
    {
        $endpoint = new PullEndpoint(
            $wordPress,
            static fn (): array => self::monitor($wordPress)->snapshot()->toArray(),
            $config
        );

        [$status, $payload] = $endpoint->respond(
            isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
            self::requestHeaders(),
            (string) file_get_contents('php://input')
        );

        status_header($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        echo wp_json_encode($payload, JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * @return array<string, string>
     */
    private static function requestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (! is_string($value) || ! str_starts_with((string) $key, 'HTTP_')) {
                continue;
            }

            $name = str_replace('_', '-', strtolower(substr((string) $key, 5)));
            $headers[$name] = $value;
        }

        return $headers;
    }
}

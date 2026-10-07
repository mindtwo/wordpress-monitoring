<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress;

use Mindtwo\Monitoring\Monitor;
use Mindtwo\Monitoring\WordPress\Admin\SettingsPage;
use Mindtwo\Monitoring\WordPress\Http\PullEndpoint;
use Mindtwo\Monitoring\WordPress\Scheduler\PushScheduler;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\Updates\GitHubReleaseUpdater;
use Mindtwo\Monitoring\WordPress\WordPress\NativeWordPressApi;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * WordPress glue: registers hooks, the rewrite-based pull endpoint, the cron
 * push, the settings page and — for ZIP installs — the GitHub update source.
 * All decision logic lives in the unit-tested classes this one wires together.
 */
final class Plugin
{
    public const QUERY_VAR = 'mindtwo_monitoring';

    public const ROUTE = 'api/app-monitoring';

    private static ?Monitor $monitor = null;

    /**
     * @param  bool  $bundled  Whether the plugin runs on its own vendor/ (release ZIP) instead of a project-wide Composer autoloader.
     */
    public static function boot(string $pluginFile, bool $bundled = false): void
    {
        $wordPress = new NativeWordPressApi;
        $config = new WordPressConfigurationRepository($wordPress);
        $scheduler = new PushScheduler($wordPress, static fn () => self::monitor($wordPress)->push(), $config);

        if ($bundled && $config->selfUpdateEnabled()) {
            self::registerUpdater(new GitHubReleaseUpdater($wordPress, plugin_basename($pluginFile)));
        }

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

    private static function registerUpdater(GitHubReleaseUpdater $updater): void
    {
        add_filter('update_plugins_github.com', [$updater, 'filterUpdate'], 10, 3);
        add_filter('plugins_api', [$updater, 'filterPluginInformation'], 10, 3);

        // "Check again" on Dashboard → Updates should see a fresh release at once.
        add_action('load-update-core.php', static function () use ($updater): void {
            if (isset($_GET['force-check'])) {
                $updater->flush();
            }
        });

        add_action('upgrader_process_complete', static function () use ($updater): void {
            $updater->flush();
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

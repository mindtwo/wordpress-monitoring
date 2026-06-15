<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress;

use Mindtwo\Monitoring\Collectors\DefaultCollectors;
use Mindtwo\Monitoring\Data\Source;
use Mindtwo\Monitoring\Monitor;
use Mindtwo\Monitoring\SnapshotBuilder;
use Mindtwo\Monitoring\SnapshotFactory;
use Mindtwo\Monitoring\Transport\HmacRequestSigner;
use Mindtwo\Monitoring\Transport\HttpTransport;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressEnvironmentCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressPluginsCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressThemesCollector;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Assembles a fully wired Monitor for this WordPress installation: the base
 * collector catalog against the detected project root plus the WordPress
 * collectors, with the HTTP push transport from the configuration.
 */
final class MonitorFactory
{
    public static function make(WordPressApi $wordPress, ?WordPressConfigurationRepository $config = null): Monitor
    {
        $config ??= new WordPressConfigurationRepository($wordPress);
        $projectRoot = self::projectRoot($wordPress, $config);

        $environment = $wordPress->environmentType() ?? 'production';
        $projectKey = $config->credentials()->projectKey;

        $factory = new SnapshotFactory(
            Source::plugin(Source::TYPE_WORDPRESS, 'mindtwo/wordpress-monitoring'),
            $environment,
            $projectKey !== '' ? $projectKey : null
        );

        $transport = new HttpTransport(
            $config->endpoint(),
            $config->credentials(),
            new HmacRequestSigner,
            max(1, $config->integer('timeout'))
        );

        $monitor = new Monitor(new SnapshotBuilder($factory), $transport);

        $monitor->replace(...DefaultCollectors::make(projectRoot: $projectRoot));
        $monitor->replace(
            new WordPressCollector($wordPress),
            new WordPressPluginsCollector($wordPress),
            new WordPressThemesCollector($wordPress),
            new WordPressEnvironmentCollector($wordPress),
        );

        return $monitor;
    }

    /**
     * The directory composer.lock & co. live in. Classic installs keep
     * everything in ABSPATH; Bedrock-style installs keep the manifest one or
     * two levels above the WordPress core directory. Configurable via the
     * project_root setting / MONITORING_PROJECT_ROOT.
     */
    public static function projectRoot(WordPressApi $wordPress, WordPressConfigurationRepository $config): string
    {
        $configured = trim((string) $config->get('project_root', ''));

        if ($configured !== '' && is_dir($configured)) {
            return $configured;
        }

        $absPath = $wordPress->absPath() ?? (string) getcwd();
        $absPath = rtrim($absPath, '/');

        foreach ([$absPath, dirname($absPath), dirname($absPath, 2)] as $candidate) {
            if ($candidate !== '' && is_file($candidate.'/composer.json')) {
                return $candidate;
            }
        }

        return $absPath !== '' ? $absPath : (string) getcwd();
    }
}

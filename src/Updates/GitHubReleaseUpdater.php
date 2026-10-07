<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Updates;

use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Update source for ZIP installs (sites without Composer). The plugin header
 * declares `Update URI: https://github.com/mindtwo/wordpress-monitoring`, so
 * WordPress asks the `update_plugins_github.com` filter instead of
 * wordpress.org. The answer comes from the latest GitHub release and only
 * points at the built `wordpress-monitoring-<version>.zip` asset — the
 * auto-generated source archive lacks vendor/ and must never be installed.
 */
final class GitHubReleaseUpdater
{
    public const UPDATE_URI = 'https://github.com/mindtwo/wordpress-monitoring';

    public const RELEASE_API = 'https://api.github.com/repos/mindtwo/wordpress-monitoring/releases/latest';

    public const SLUG = 'wordpress-monitoring';

    public const CACHE_TRANSIENT = 'mindtwo_monitoring_release';

    /** WordPress itself checks twice a day; unauthenticated GitHub allows 60 requests/hour per IP. */
    public const CACHE_SECONDS = 6 * 3600;

    /** Shared hosting shares the GitHub rate limit across all sites on an IP. */
    public const FAILURE_CACHE_SECONDS = 3600;

    private const REQUIRES_WORDPRESS = '6.0';

    private const REQUIRES_PHP = '8.0';

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private WordPressApi $wordPress,
        private string $pluginFile
    ) {}

    /**
     * Callback for `update_plugins_{hostname}`.
     *
     * @param  array<string, mixed>|false  $update
     * @param  array<string, mixed>  $pluginData
     * @return array<string, mixed>|false
     */
    public function filterUpdate($update, array $pluginData, string $pluginFile)
    {
        if ($pluginFile !== $this->pluginFile) {
            return $update;
        }

        $release = $this->latestRelease();
        $installed = isset($pluginData['Version']) && is_string($pluginData['Version']) ? $pluginData['Version'] : '0';

        if ($release === null || version_compare($release['version'], $installed, '<=')) {
            return false;
        }

        return [
            'id' => self::UPDATE_URI,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => self::REQUIRES_WORDPRESS,
            'requires_php' => self::REQUIRES_PHP,
        ];
    }

    /**
     * Callback for `plugins_api`: fills the "View version details" modal,
     * which would otherwise ask wordpress.org and show "Plugin not found".
     *
     * @param  false|object|array<mixed>  $result
     * @return false|object|array<mixed>
     */
    public function filterPluginInformation($result, string $action, object $args)
    {
        if ($action !== 'plugin_information' || ! isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $release = $this->latestRelease();

        if ($release === null) {
            return $result;
        }

        return (object) [
            'name' => 'mindtwo Monitoring',
            'slug' => self::SLUG,
            'version' => $release['version'],
            'author' => '<a href="https://www.mindtwo.de">mindtwo GmbH</a>',
            'homepage' => self::UPDATE_URI,
            'requires' => self::REQUIRES_WORDPRESS,
            'requires_php' => self::REQUIRES_PHP,
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Reports infrastructure, package and security data of this site to the mindtwo monitoring dashboard.',
                'changelog' => nl2br(htmlspecialchars($release['notes'], ENT_QUOTES, 'UTF-8')),
            ],
        ];
    }

    public function flush(): void
    {
        $this->wordPress->deleteTransient(self::CACHE_TRANSIENT);
    }

    /**
     * The latest usable release, cached. Failures are cached as an empty
     * array for a shorter time so a rate-limited host is not hammered.
     *
     * @return array{version: string, url: string, package: string, published_at: string, notes: string}|null
     */
    private function latestRelease(): ?array
    {
        $cached = $this->wordPress->transient(self::CACHE_TRANSIENT);

        if (is_array($cached)) {
            return $cached === [] ? null : $this->release($cached);
        }

        $release = $this->fetch();

        $this->wordPress->setTransient(
            self::CACHE_TRANSIENT,
            $release ?? [],
            $release !== null ? self::CACHE_SECONDS : self::FAILURE_CACHE_SECONDS
        );

        return $release;
    }

    /**
     * @return array{version: string, url: string, package: string, published_at: string, notes: string}|null
     */
    private function fetch(): ?array
    {
        $response = $this->wordPress->remoteGet(self::RELEASE_API, [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'mindtwo-wordpress-monitoring',
        ], self::TIMEOUT_SECONDS);

        if ($response === null || $response['status'] !== 200) {
            return null;
        }

        $data = json_decode($response['body'], true);

        if (! is_array($data) || ! isset($data['tag_name']) || ! is_string($data['tag_name'])) {
            return null;
        }

        $version = ltrim($data['tag_name'], 'v');

        if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
            return null;
        }

        $package = $this->packageUrl($data['assets'] ?? null, self::SLUG.'-'.$version.'.zip');

        if ($package === null) {
            return null;
        }

        return [
            'version' => $version,
            'url' => isset($data['html_url']) && is_string($data['html_url']) ? $data['html_url'] : self::UPDATE_URI.'/releases',
            'package' => $package,
            'published_at' => isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : '',
            'notes' => isset($data['body']) && is_string($data['body']) ? $data['body'] : '',
        ];
    }

    /**
     * @param  mixed  $assets
     */
    private function packageUrl($assets, string $expectedName): ?string
    {
        if (! is_array($assets)) {
            return null;
        }

        foreach ($assets as $asset) {
            if (! is_array($asset) || ($asset['name'] ?? null) !== $expectedName) {
                continue;
            }

            $url = $asset['browser_download_url'] ?? null;

            if (is_string($url) && str_starts_with($url, self::UPDATE_URI.'/releases/download/')) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Re-validates a cached entry (transients may be tampered with or stale
     * in shape after a plugin update).
     *
     * @param  array<mixed>  $cached
     * @return array{version: string, url: string, package: string, published_at: string, notes: string}|null
     */
    private function release(array $cached): ?array
    {
        foreach (['version', 'url', 'package', 'published_at', 'notes'] as $key) {
            if (! isset($cached[$key]) || ! is_string($cached[$key])) {
                return null;
            }
        }

        if (! str_starts_with($cached['package'], self::UPDATE_URI.'/releases/download/')) {
            return null;
        }

        return [
            'version' => $cached['version'],
            'url' => $cached['url'],
            'package' => $cached['package'],
            'published_at' => $cached['published_at'],
            'notes' => $cached['notes'],
        ];
    }
}

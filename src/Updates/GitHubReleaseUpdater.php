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
 *
 * The minimum WordPress/PHP versions of a newer release are read from the
 * plugin header of its tag: WordPress' automatic updater trusts the
 * `requires_php` of this answer, so it must describe the offered release —
 * a raised minimum would otherwise be auto-installed onto too old a PHP.
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

    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private WordPressApi $wordPress,
        private string $pluginFile
    ) {}

    /**
     * Callback for `update_plugins_{hostname}`. Reports the latest release
     * regardless of the installed version: WordPress compares the versions
     * itself and files a current plugin under `no_update`, which is what
     * enables the auto-update toggle and the "View details" link.
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

        if ($release === null) {
            return false;
        }

        $installed = isset($pluginData['Version']) && is_string($pluginData['Version']) ? $pluginData['Version'] : '0';

        if (version_compare($release['version'], $installed, '>')) {
            $requirements = $this->requirements($release);

            if ($requirements === null) {
                return false;
            }
        } else {
            // Not offered as an update: the installed plugin's own header applies.
            $requirements = [
                'requires' => isset($pluginData['RequiresWP']) && is_string($pluginData['RequiresWP']) ? $pluginData['RequiresWP'] : '',
                'requires_php' => isset($pluginData['RequiresPHP']) && is_string($pluginData['RequiresPHP']) ? $pluginData['RequiresPHP'] : '',
            ];
        }

        return [
            'id' => self::UPDATE_URI,
            'slug' => self::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires' => $requirements['requires'],
            'requires_php' => $requirements['requires_php'],
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
            'requires' => $release['requires'],
            'requires_php' => $release['requires_php'],
            'last_updated' => $release['published_at'],
            'download_link' => $release['package'],
            'sections' => [
                'description' => 'Reports infrastructure, package and security data of this site to the mindtwo monitoring dashboard.',
                // Release notes are Markdown; link the rendered version instead of parsing it.
                'changelog' => '<p><a href="'.htmlspecialchars($release['url'], ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">Release notes on GitHub</a></p>'
                    .nl2br(htmlspecialchars($release['notes'], ENT_QUOTES, 'UTF-8')),
            ],
        ];
    }

    public static function pluginHeaderUrl(string $tag): string
    {
        return 'https://raw.githubusercontent.com/mindtwo/wordpress-monitoring/'.rawurlencode($tag).'/wordpress-monitoring.php';
    }

    /**
     * Callback for `upgrader_process_complete`: only an upgrade of this
     * plugin invalidates the release cache — every flush costs an
     * unauthenticated GitHub request on IPs shared by many sites.
     *
     * @param  array<string, mixed>  $hookExtra
     */
    public function onUpgradeComplete(array $hookExtra): void
    {
        if (($hookExtra['type'] ?? null) !== 'plugin') {
            return;
        }

        $plugins = isset($hookExtra['plugins']) && is_array($hookExtra['plugins']) ? $hookExtra['plugins'] : [];

        if (isset($hookExtra['plugin'])) {
            $plugins[] = $hookExtra['plugin'];
        }

        if (in_array($this->pluginFile, $plugins, true)) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        $this->wordPress->deleteSiteTransient(self::CACHE_TRANSIENT);
    }

    /**
     * The latest usable release, cached network-wide (like core's
     * update_plugins). Failures are cached as an empty array for a shorter
     * time so a rate-limited host is not hammered; an invalid entry is
     * refetched instead of blocking updates until it expires.
     *
     * @return array{tag: string, version: string, url: string, package: string, published_at: string, notes: string, requires: string|null, requires_php: string|null}|null
     */
    private function latestRelease(): ?array
    {
        $cached = $this->wordPress->siteTransient(self::CACHE_TRANSIENT);

        if ($cached === []) {
            return null;
        }

        if (is_array($cached)) {
            $release = $this->release($cached);

            if ($release !== null) {
                return $release;
            }
        }

        $release = $this->fetch();

        $this->wordPress->setSiteTransient(
            self::CACHE_TRANSIENT,
            $release ?? [],
            $release !== null ? self::CACHE_SECONDS : self::FAILURE_CACHE_SECONDS
        );

        return $release;
    }

    /**
     * Requirements of the given release, fetched once from its tagged plugin
     * header and cached with the release. Unreadable requirements fail closed
     * and are cached like a failed release lookup.
     *
     * @param  array{tag: string, version: string, url: string, package: string, published_at: string, notes: string, requires: string|null, requires_php: string|null}  $release
     * @return array{requires: string, requires_php: string}|null
     */
    private function requirements(array $release): ?array
    {
        if ($release['requires'] !== null && $release['requires_php'] !== null) {
            return ['requires' => $release['requires'], 'requires_php' => $release['requires_php']];
        }

        $requirements = $this->fetchRequirements($release['tag']);

        if ($requirements === null) {
            $this->wordPress->setSiteTransient(self::CACHE_TRANSIENT, [], self::FAILURE_CACHE_SECONDS);

            return null;
        }

        $this->wordPress->setSiteTransient(self::CACHE_TRANSIENT, array_merge($release, $requirements), self::CACHE_SECONDS);

        return $requirements;
    }

    /**
     * @return array{requires: string, requires_php: string}|null
     */
    private function fetchRequirements(string $tag): ?array
    {
        $response = $this->wordPress->remoteGet(self::pluginHeaderUrl($tag), [
            'User-Agent' => 'mindtwo-wordpress-monitoring',
        ], self::TIMEOUT_SECONDS);

        if ($response === null || $response['status'] !== 200) {
            return null;
        }

        // Same window and line format as WordPress' get_file_data().
        $header = substr($response['body'], 0, 8192);
        $requirements = [];

        foreach (['requires' => 'Requires at least', 'requires_php' => 'Requires PHP'] as $key => $name) {
            if (preg_match('/^[ \t\/*#@]*'.preg_quote($name, '/').':[ \t]*(\d+(?:\.\d+)*)[ \t]*$/mi', $header, $match) !== 1) {
                return null;
            }

            $requirements[$key] = $match[1];
        }

        return ['requires' => $requirements['requires'], 'requires_php' => $requirements['requires_php']];
    }

    /**
     * @return array{tag: string, version: string, url: string, package: string, published_at: string, notes: string, requires: string|null, requires_php: string|null}|null
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
            'tag' => $data['tag_name'],
            'version' => $version,
            'url' => isset($data['html_url']) && is_string($data['html_url']) ? $data['html_url'] : self::UPDATE_URI.'/releases',
            'package' => $package,
            'published_at' => isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : '',
            'notes' => isset($data['body']) && is_string($data['body']) ? $data['body'] : '',
            'requires' => null,
            'requires_php' => null,
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
     * @return array{tag: string, version: string, url: string, package: string, published_at: string, notes: string, requires: string|null, requires_php: string|null}|null
     */
    private function release(array $cached): ?array
    {
        foreach (['tag', 'version', 'url', 'package', 'published_at', 'notes'] as $key) {
            if (! isset($cached[$key]) || ! is_string($cached[$key])) {
                return null;
            }
        }

        $requires = isset($cached['requires']) && is_string($cached['requires']) ? $cached['requires'] : null;
        $requiresPhp = isset($cached['requires_php']) && is_string($cached['requires_php']) ? $cached['requires_php'] : null;

        if (! str_starts_with($cached['package'], self::UPDATE_URI.'/releases/download/')) {
            return null;
        }

        return [
            'tag' => $cached['tag'],
            'version' => $cached['version'],
            'url' => $cached['url'],
            'package' => $cached['package'],
            'published_at' => $cached['published_at'],
            'notes' => $cached['notes'],
            'requires' => $requires,
            'requires_php' => $requiresPhp,
        ];
    }
}

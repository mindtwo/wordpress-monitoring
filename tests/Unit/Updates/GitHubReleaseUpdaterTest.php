<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;
use Mindtwo\Monitoring\WordPress\Updates\GitHubReleaseUpdater;

const UPDATER_PLUGIN_FILE = 'wordpress-monitoring/wordpress-monitoring.php';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function githubRelease(string $tag = 'v1.2.0', array $overrides = []): array
{
    $version = ltrim($tag, 'v');

    return array_merge([
        'tag_name' => $tag,
        'html_url' => 'https://github.com/mindtwo/wordpress-monitoring/releases/tag/'.$tag,
        'published_at' => '2026-10-07T10:00:00Z',
        'body' => "### Added\n- Self-update <b>support</b>",
        'assets' => [
            [
                'name' => 'wordpress-monitoring-'.$version.'.zip',
                'browser_download_url' => 'https://github.com/mindtwo/wordpress-monitoring/releases/download/'.$tag.'/wordpress-monitoring-'.$version.'.zip',
            ],
        ],
    ], $overrides);
}

/**
 * Arranges the release API answer and, for a well-formed release, the plugin
 * header of the tagged version (where the requirements of a newer release
 * are read from).
 */
function releaseResponse(FakeWordPressApi $wordPress, int $status, mixed $body): void
{
    $wordPress->remoteResponses[GitHubReleaseUpdater::RELEASE_API] = [
        'status' => $status,
        'body' => is_string($body) ? $body : (string) json_encode($body),
    ];

    if (is_array($body) && isset($body['tag_name'])) {
        pluginHeaderResponse($wordPress, $body['tag_name']);
    }
}

function pluginHeaderResponse(FakeWordPressApi $wordPress, string $tag, string $requiresWordPress = '6.0', string $requiresPhp = '8.0', int $status = 200): void
{
    $wordPress->remoteResponses[GitHubReleaseUpdater::pluginHeaderUrl($tag)] = [
        'status' => $status,
        'body' => "<?php\n\n/**\n * Plugin Name:       mindtwo Monitoring\n * Version:           ".ltrim($tag, 'v')
            ."\n * Requires at least: {$requiresWordPress}\n * Requires PHP:      {$requiresPhp}\n */\n",
    ];
}

function requestsTo(FakeWordPressApi $wordPress, string $url): int
{
    return count(array_filter($wordPress->remoteRequests, static fn (array $request): bool => $request['url'] === $url));
}

function updater(FakeWordPressApi $wordPress): GitHubReleaseUpdater
{
    return new GitHubReleaseUpdater($wordPress, UPDATER_PLUGIN_FILE);
}

test('a newer release with a zip asset is offered as an update', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));

    $update = updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect($update)->toBe([
        'id' => GitHubReleaseUpdater::UPDATE_URI,
        'slug' => 'wordpress-monitoring',
        'version' => '1.2.0',
        'url' => 'https://github.com/mindtwo/wordpress-monitoring/releases/tag/v1.2.0',
        'package' => 'https://github.com/mindtwo/wordpress-monitoring/releases/download/v1.2.0/wordpress-monitoring-1.2.0.zip',
        'requires' => '6.0',
        'requires_php' => '8.0',
    ]);
});

test('a newer release carries the requirements declared in its own plugin header', function () {
    // WordPress' automatic updater only checks requires_php from this answer,
    // so it must describe the offered release, not the installed one.
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));
    pluginHeaderResponse($wordPress, 'v1.2.0', '6.4', '8.1');

    $update = updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0', 'RequiresWP' => '6.0', 'RequiresPHP' => '8.0'], UPDATER_PLUGIN_FILE);

    expect($update)->toBeArray()
        ->and($update['requires'])->toBe('6.4')
        ->and($update['requires_php'])->toBe('8.1');
});

test('a newer release is not offered when its requirements cannot be read', function (int $status, string $body) {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));
    $wordPress->remoteResponses[GitHubReleaseUpdater::pluginHeaderUrl('v1.2.0')] = ['status' => $status, 'body' => $body];

    $updater = updater($wordPress);

    expect($updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse();

    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect(requestsTo($wordPress, GitHubReleaseUpdater::pluginHeaderUrl('v1.2.0')))->toBe(1)
        ->and($wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT][1])->toBe(GitHubReleaseUpdater::FAILURE_CACHE_SECONDS);
})->with([
    'not found' => [404, '404: Not Found'],
    'header without requirements' => [200, "<?php\n/**\n * Plugin Name: mindtwo Monitoring\n */\n"],
    'malformed requirement' => [200, "<?php\n/**\n * Requires at least: 6.0\n * Requires PHP: latest\n */\n"],
]);

test('a current plugin reports its installed requirements without fetching the header', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));

    $update = updater($wordPress)->filterUpdate(false, ['Version' => '1.2.0', 'RequiresWP' => '6.0', 'RequiresPHP' => '8.0'], UPDATER_PLUGIN_FILE);

    expect($update)->toBeArray()
        ->and($update['requires'])->toBe('6.0')
        ->and($update['requires_php'])->toBe('8.0')
        ->and(requestsTo($wordPress, GitHubReleaseUpdater::pluginHeaderUrl('v1.2.0')))->toBe(0);
});

test('the github api is called with the headers it requires', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect(requestsTo($wordPress, GitHubReleaseUpdater::RELEASE_API))->toBe(1)
        ->and($wordPress->remoteRequests[0]['url'])->toBe(GitHubReleaseUpdater::RELEASE_API)
        ->and($wordPress->remoteRequests[0]['headers'])->toHaveKeys(['Accept', 'User-Agent']);
});

test('other plugins sharing the github.com update hostname are left alone', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    $foreign = ['version' => '9.9.9'];
    $update = updater($wordPress)->filterUpdate($foreign, ['Version' => '1.0.0'], 'other-plugin/other-plugin.php');

    expect($update)->toBe($foreign)
        ->and($wordPress->remoteRequests)->toBe([]);
});

test('the latest release is reported even when the installed version is current or newer', function (string $installed) {
    // WordPress compares versions itself and files the entry under no_update.
    // Returning false instead would hide the auto-update toggle and the
    // "View details" link whenever the plugin is up to date.
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));

    $update = updater($wordPress)->filterUpdate(false, ['Version' => $installed], UPDATER_PLUGIN_FILE);

    expect($update)->toBeArray()
        ->and($update['version'])->toBe('1.2.0');
})->with(['1.2.0', '1.3.0']);

test('a release without the built zip asset is never offered', function () {
    // The auto-generated source archive lacks vendor/ and would leave the site
    // with a silently inactive plugin, so it must not be used as a fallback.
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0', ['assets' => []]));

    expect(updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse();
});

test('a download url outside github is rejected', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0', ['assets' => [[
        'name' => 'wordpress-monitoring-1.2.0.zip',
        'browser_download_url' => 'https://evil.example/wordpress-monitoring-1.2.0.zip',
    ]]]));

    expect(updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse();
});

test('tags that are not semantic versions are ignored', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('nightly'));

    expect(updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse();
});

test('the release lookup is cached', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    $updater = updater($wordPress);
    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);
    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect(requestsTo($wordPress, GitHubReleaseUpdater::RELEASE_API))->toBe(1)
        ->and(requestsTo($wordPress, GitHubReleaseUpdater::pluginHeaderUrl('v1.2.0')))->toBe(1)
        ->and($wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT][1])->toBe(GitHubReleaseUpdater::CACHE_SECONDS);
});

test('failed lookups are cached briefly so a rate-limited host is not hammered', function (int $status, mixed $body) {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, $status, $body);

    $updater = updater($wordPress);

    expect($updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse();

    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect($wordPress->remoteRequests)->toHaveCount(1)
        ->and($wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT][1])->toBe(GitHubReleaseUpdater::FAILURE_CACHE_SECONDS);
})->with([
    'rate limited' => [403, ['message' => 'API rate limit exceeded']],
    'no release yet' => [404, ['message' => 'Not Found']],
    'malformed json' => [200, '{not json'],
]);

test('a transport error is treated like a failed lookup', function () {
    $wordPress = new FakeWordPressApi;

    expect(updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE))->toBeFalse()
        ->and($wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT][1])->toBe(GitHubReleaseUpdater::FAILURE_CACHE_SECONDS);
});

test('flushing the cache forces a fresh lookup', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    $updater = updater($wordPress);
    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);
    $updater->flush();
    $updater->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect(requestsTo($wordPress, GitHubReleaseUpdater::RELEASE_API))->toBe(2);
});

test('the details modal is answered for the own slug only', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));

    $updater = updater($wordPress);
    $foreign = $updater->filterPluginInformation(false, 'plugin_information', (object) ['slug' => 'akismet']);
    $info = $updater->filterPluginInformation(false, 'plugin_information', (object) ['slug' => 'wordpress-monitoring']);

    expect($foreign)->toBeFalse()
        ->and($info)->toBeObject()
        ->and($info->slug)->toBe('wordpress-monitoring')
        ->and($info->version)->toBe('1.2.0')
        ->and($info->download_link)->toEndWith('wordpress-monitoring-1.2.0.zip')
        ->and($info->sections['changelog'])->toContain('href="https://github.com/mindtwo/wordpress-monitoring/releases/tag/v1.2.0"')
        ->and($info->sections['changelog'])->toContain('&lt;b&gt;support&lt;/b&gt;')
        ->and($info->sections['changelog'])->not->toContain('<b>');
});

test('other plugins_api actions pass through untouched', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    $result = updater($wordPress)->filterPluginInformation(false, 'query_plugins', (object) ['slug' => 'wordpress-monitoring']);

    expect($result)->toBeFalse()
        ->and($wordPress->remoteRequests)->toBe([]);
});

test('the cache is a site transient shared by all sites of a network', function () {
    // Core keeps update_plugins network-wide too; a per-site cache would
    // multiply GitHub requests and miss the network admin's "Check again".
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease());

    updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect($wordPress->siteTransients)->toHaveKey(GitHubReleaseUpdater::CACHE_TRANSIENT)
        ->and($wordPress->transients)->not->toHaveKey(GitHubReleaseUpdater::CACHE_TRANSIENT);
});

test('a cached release is answered without asking github again', function () {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));

    $first = updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);
    $wordPress->remoteRequests = [];
    $second = updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect($second)->toBe($first)
        ->and($wordPress->remoteRequests)->toBe([]);
});

test('a tampered or outdated cache entry is discarded and fetched fresh', function (array $cached) {
    $wordPress = new FakeWordPressApi;
    releaseResponse($wordPress, 200, githubRelease('v1.2.0'));
    $wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT] = [$cached, GitHubReleaseUpdater::CACHE_SECONDS];

    $update = updater($wordPress)->filterUpdate(false, ['Version' => '1.0.0'], UPDATER_PLUGIN_FILE);

    expect($update)->toBeArray()
        ->and($update['package'])->toBe('https://github.com/mindtwo/wordpress-monitoring/releases/download/v1.2.0/wordpress-monitoring-1.2.0.zip')
        ->and(requestsTo($wordPress, GitHubReleaseUpdater::RELEASE_API))->toBe(1);
})->with([
    'foreign package url' => [[
        'tag' => 'v9.9.9', 'version' => '9.9.9', 'url' => 'https://evil.example', 'package' => 'https://evil.example/plugin.zip',
        'published_at' => '', 'notes' => '', 'requires' => '6.0', 'requires_php' => '8.0',
    ]],
    'outdated shape' => [['version' => '1.2.0', 'package' => 'https://github.com/mindtwo/wordpress-monitoring/releases/download/v1.2.0/wordpress-monitoring-1.2.0.zip']],
]);

test('the cache is flushed after this plugin was upgraded', function (array $hookExtra) {
    $wordPress = new FakeWordPressApi;
    $wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT] = [[], GitHubReleaseUpdater::FAILURE_CACHE_SECONDS];

    updater($wordPress)->onUpgradeComplete($hookExtra);

    expect($wordPress->siteTransients)->not->toHaveKey(GitHubReleaseUpdater::CACHE_TRANSIENT);
})->with([
    'single update' => [['type' => 'plugin', 'action' => 'update', 'plugin' => UPDATER_PLUGIN_FILE]],
    'bulk update' => [['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php', UPDATER_PLUGIN_FILE]]],
]);

test('upgrades of anything else keep the cache', function (array $hookExtra) {
    // Each flush costs an unauthenticated GitHub request on shared IPs.
    $wordPress = new FakeWordPressApi;
    $wordPress->siteTransients[GitHubReleaseUpdater::CACHE_TRANSIENT] = [[], GitHubReleaseUpdater::FAILURE_CACHE_SECONDS];

    updater($wordPress)->onUpgradeComplete($hookExtra);

    expect($wordPress->siteTransients)->toHaveKey(GitHubReleaseUpdater::CACHE_TRANSIENT);
})->with([
    'other plugin' => [['type' => 'plugin', 'action' => 'update', 'plugins' => ['akismet/akismet.php']]],
    'theme' => [['type' => 'theme', 'action' => 'update', 'themes' => ['twentytwentyfive']]],
    'core' => [['type' => 'core', 'action' => 'update']],
    'translations' => [['type' => 'translation', 'action' => 'update', 'translations' => []]],
]);

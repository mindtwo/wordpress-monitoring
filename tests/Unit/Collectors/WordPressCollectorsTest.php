<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Collectors\WordPressCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressEnvironmentCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressPluginsCollector;
use Mindtwo\Monitoring\WordPress\Collectors\WordPressThemesCollector;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

test('wordpress core is reported as a known technology', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->version = '6.5.3';

    $result = (new WordPressCollector($wordPress))->collect();

    expect($result->status)->toBe('ok')
        ->and($result->data)->toBe([
            'technology' => 'wordpress',
            'version' => '6.5.3',
            'multisite' => false,
        ]);
});

test('an unknown core version marks the collector unsupported', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->version = null;

    $collector = new WordPressCollector($wordPress);

    expect($collector->supported())->toBeFalse()
        ->and($collector->collect()->status)->toBe('unsupported');
});

test('plugins are listed with activity, versions and updates', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->plugins = [
        'seo/seo.php' => ['Name' => 'SEO Tool', 'Version' => '21.5'],
        'forms/forms.php' => ['Name' => 'Forms', 'Version' => '3.1.2'],
        'legacy/legacy.php' => ['Name' => 'Legacy'],
    ];
    $wordPress->activePlugins = ['seo/seo.php'];
    $wordPress->pluginUpdates = ['forms/forms.php' => '3.2.0'];

    $result = (new WordPressPluginsCollector($wordPress))->collect();

    expect($result->status)->toBe('warning')
        ->and($result->data['count'])->toBe(3)
        ->and($result->data['active_count'])->toBe(1)
        ->and($result->data['updates_available'])->toBe(1)
        ->and($result->data['plugins'][0])->toBe([
            'file' => 'seo/seo.php',
            'name' => 'SEO Tool',
            'version' => '21.5',
            'active' => true,
            'update_available' => null,
        ])
        ->and($result->data['plugins'][1]['update_available'])->toBe('3.2.0')
        ->and($result->data['plugins'][2]['version'])->toBeNull();
});

test('a fully up-to-date plugin list reports ok', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->plugins = ['seo/seo.php' => ['Name' => 'SEO', 'Version' => '1.0']];

    expect((new WordPressPluginsCollector($wordPress))->collect()->status)->toBe('ok');
});

test('themes are listed with the active flag and updates', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->themes = [
        'agency-theme' => ['name' => 'Agency Theme', 'version' => '2.4.0', 'active' => true],
        'twentytwentyfour' => ['name' => 'Twenty Twenty-Four', 'version' => '1.1', 'active' => false],
    ];
    $wordPress->themeUpdates = ['twentytwentyfour' => '1.2'];

    $result = (new WordPressThemesCollector($wordPress))->collect();

    expect($result->status)->toBe('warning')
        ->and($result->data['count'])->toBe(2)
        ->and($result->data['updates_available'])->toBe(1)
        ->and($result->data['themes'][0]['active'])->toBeTrue()
        ->and($result->data['themes'][1]['update_available'])->toBe('1.2');
});

test('the environment collector reports operational state', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->constants['WP_DEBUG'] = true;
    $wordPress->multisite = true;

    $result = (new WordPressEnvironmentCollector($wordPress))->collect();

    expect($result->status)->toBe('ok')
        ->and($result->data)->toBe([
            'environment_type' => 'production',
            'debug' => true,
            'debug_display' => false,
            'multisite' => true,
            'language' => 'de_DE',
            'timezone' => 'Europe/Berlin',
            'site_url' => 'https://example.com',
        ]);
});

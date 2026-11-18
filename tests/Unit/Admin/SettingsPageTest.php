<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Admin\SettingsPage;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

test('saving the settings drops backend values that a constant overrides', function () {
    // So the next database copy carries no live key or secret.
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['project_key' => 'prj_old', 'secret' => 'old-secret'];
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_live';
    $wordPress->constants['MONITORING_SECRET'] = 'live-secret';

    $saved = (new SettingsPage($wordPress))->sanitize(['enabled' => '1', 'route_enabled' => '1', 'schedule_enabled' => '1']);

    expect($saved['project_key'])->toBe('')
        ->and($saved['secret'])->toBe('');
});

test('a switch locked by a constant keeps its stored value on save', function () {
    // Locked checkboxes are rendered disabled and therefore never submitted.
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['enabled' => true, 'route_enabled' => true];
    $wordPress->constants['MONITORING_ENABLED'] = false;

    $saved = (new SettingsPage($wordPress))->sanitize(['route_enabled' => '1']);

    expect($saved['enabled'])->toBeTrue()
        ->and($saved['route_enabled'])->toBeTrue()
        ->and($saved['schedule_enabled'])->toBeFalse();
});

test('unlocked fields are saved as before', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['secret' => 'kept-secret'];

    $saved = (new SettingsPage($wordPress))->sanitize([
        'enabled' => '1',
        'project_key' => ' prj_test ',
        'secret' => '',
        'endpoint' => 'https://example.test/api',
    ]);

    expect($saved['project_key'])->toBe('prj_test')
        ->and($saved['secret'])->toBe('kept-secret')
        ->and($saved['endpoint'])->toBe('https://example.test/api')
        ->and($saved['enabled'])->toBeTrue();
});

function renderSettings(FakeWordPressApi $wordPress): string
{
    require_once __DIR__.'/../../Support/render-functions.php';

    ob_start();
    (new SettingsPage($wordPress))->render();

    return (string) ob_get_clean();
}

function field(string $html, string $id): string
{
    preg_match('/<input[^>]*id="'.$id.'"[^>]*>/', $html, $match);

    return $match[0] ?? '';
}

test('fields overridden by a constant render disabled, empty and named', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['project_key' => 'prj_db', 'secret' => 'db-secret', 'endpoint' => 'https://db.example'];
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_live';
    $wordPress->constants['MONITORING_SECRET'] = 'live-secret';
    $wordPress->envs['MONITORING_ENDPOINT'] = 'https://env.example';

    $html = renderSettings($wordPress);

    expect(field($html, 'm2-project-key'))->toContain(' disabled')->toContain('value=""')
        ->and(field($html, 'm2-secret'))->toContain(' disabled')->toContain('placeholder=""')
        ->and(field($html, 'm2-endpoint'))->toContain(' disabled')->toContain('placeholder=""')
        ->and($html)->toContain('Set by <code>MONITORING_SECRET</code>')
        ->and($html)->toContain('Set by <code>MONITORING_ENDPOINT</code>')
        ->and($html)->not->toContain('prj_db')
        ->and($html)->not->toContain('prj_live')
        ->and($html)->not->toContain('db-secret')
        ->and($html)->not->toContain('live-secret')
        ->and($html)->not->toContain('env.example');
});

test('a locked switch shows the value in effect', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['enabled' => true];
    $wordPress->constants['MONITORING_ENABLED'] = false;

    $enabled = field(renderSettings($wordPress), 'm2-enabled');

    expect($enabled)->toContain(' disabled')
        ->and($enabled)->not->toContain('checked');
});

test('unlocked fields render the stored values as before', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['project_key' => 'prj_db', 'secret' => 'db-secret', 'enabled' => true];

    $html = renderSettings($wordPress);

    expect(field($html, 'm2-project-key'))->toContain('value="prj_db"')->not->toContain('disabled')
        ->and(field($html, 'm2-secret'))->toContain('placeholder="••••••••"')
        ->and(field($html, 'm2-enabled'))->toContain('checked')
        ->and($html)->not->toContain('db-secret');
});

test('sanitizing an already sanitized value keeps unchecked boxes off', function () {
    // On the very first save add_option() runs the sanitize callback a second
    // time, on the sanitized array, where unchecked boxes are false.
    $page = new SettingsPage(new FakeWordPressApi);

    $once = $page->sanitize(['enabled' => '1', 'project_key' => 'prj_test']);
    $twice = $page->sanitize($once);

    expect($twice['route_enabled'])->toBeFalse()
        ->and($twice['schedule_enabled'])->toBeFalse()
        ->and($twice['enabled'])->toBeTrue()
        ->and($twice)->toBe($once);
});

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

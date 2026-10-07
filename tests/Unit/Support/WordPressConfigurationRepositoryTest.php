<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

test('backend options are the primary source', function () {
    $repository = new WordPressConfigurationRepository(fakeWordPress());
    $credentials = $repository->credentials();

    expect($credentials->projectKey)->toBe('prj_test')
        ->and($credentials->secret)->toBe('test-secret')
        ->and($credentials->isComplete())->toBeTrue();
});

test('constants are used when the backend value is blank', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['project_key' => ''];
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_from_constant';
    $wordPress->constants['MONITORING_SECRET'] = 'secret_from_constant';

    $credentials = (new WordPressConfigurationRepository($wordPress))->credentials();

    expect($credentials->projectKey)->toBe('prj_from_constant')
        ->and($credentials->secret)->toBe('secret_from_constant');
});

test('environment variables are used when no option or constant exists', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->envs['MONITORING_PROJECT_KEY'] = 'prj_from_env';
    $wordPress->envs['MONITORING_SECRET'] = 'secret_from_env';

    $credentials = (new WordPressConfigurationRepository($wordPress))->credentials();

    expect($credentials->projectKey)->toBe('prj_from_env')
        ->and($credentials->secret)->toBe('secret_from_env');
});

test('options beat constants beat environment variables', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['endpoint' => 'https://from-option.example'];
    $wordPress->constants['MONITORING_ENDPOINT'] = 'https://from-constant.example';
    $wordPress->envs['MONITORING_ENDPOINT'] = 'https://from-env.example';

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-option.example');

    unset($wordPress->options['mindtwo_monitoring_settings']);

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-constant.example');

    $wordPress->constants = [];

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-env.example');
});

test('secure defaults apply when nothing is configured', function () {
    $repository = new WordPressConfigurationRepository(new FakeWordPressApi);

    expect($repository->credentials()->isComplete())->toBeFalse()
        ->and($repository->endpoint())->toBe('https://monitoring.mindtwo.com/api/monitoring')
        ->and($repository->ipAllowList())->toBe([])
        ->and($repository->enabled())->toBeTrue()
        ->and($repository->routeEnabled())->toBeTrue()
        ->and($repository->scheduleEnabled())->toBeTrue()
        ->and($repository->integer('cache_seconds'))->toBe(300)
        ->and($repository->integer('rate_limit_per_minute'))->toBe(10)
        ->and($repository->integer('signature_tolerance'))->toBe(300);
});

test('the ip allow-list parses comma-separated strings', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['ip_allow_list' => ' 10.0.0.0/8 , 203.0.113.10 ,, '];

    expect((new WordPressConfigurationRepository($wordPress))->ipAllowList())
        ->toBe(['10.0.0.0/8', '203.0.113.10']);
});

test('boolean settings understand string representations', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->constants['MONITORING_ENABLED'] = 'false';

    expect((new WordPressConfigurationRepository($wordPress))->enabled())->toBeFalse();

    $wordPress->constants['MONITORING_ENABLED'] = '1';

    expect((new WordPressConfigurationRepository($wordPress))->enabled())->toBeTrue();
});

test('disabling the master switch disables route and schedule', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = [
        'enabled' => false,
        'route_enabled' => true,
        'schedule_enabled' => true,
    ];

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->routeEnabled())->toBeFalse()
        ->and($repository->scheduleEnabled())->toBeFalse();
});

test('self-update is on by default and can be switched off', function () {
    $wordPress = new FakeWordPressApi;

    expect((new WordPressConfigurationRepository($wordPress))->selfUpdateEnabled())->toBeTrue();

    $wordPress->constants['MONITORING_SELF_UPDATE'] = false;

    expect((new WordPressConfigurationRepository($wordPress))->selfUpdateEnabled())->toBeFalse();
});

test('self-update stays available while data collection is disabled', function () {
    // Pausing monitoring must not cut a site off from security fixes.
    $wordPress = new FakeWordPressApi;
    $wordPress->constants['MONITORING_ENABLED'] = false;

    expect((new WordPressConfigurationRepository($wordPress))->selfUpdateEnabled())->toBeTrue();
});

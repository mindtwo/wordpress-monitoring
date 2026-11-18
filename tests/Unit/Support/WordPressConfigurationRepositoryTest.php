<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

test('backend options apply when no constant or environment variable is set', function () {
    $repository = new WordPressConfigurationRepository(fakeWordPress());
    $credentials = $repository->credentials();

    expect($credentials->projectKey)->toBe('prj_test')
        ->and($credentials->secret)->toBe('test-secret')
        ->and($credentials->isComplete())->toBeTrue();
});

test('constants override a blank backend value', function () {
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

test('constants beat environment variables beat backend options', function () {
    // Secrets belong in wp-config/.env; a value saved in the backend must not
    // override them, and must not travel along with a database copy.
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['endpoint' => 'https://from-option.example'];
    $wordPress->constants['MONITORING_ENDPOINT'] = 'https://from-constant.example';
    $wordPress->envs['MONITORING_ENDPOINT'] = 'https://from-env.example';

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-constant.example');

    $wordPress->constants = [];

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-env.example');

    $wordPress->envs = [];

    expect((new WordPressConfigurationRepository($wordPress))->endpoint())->toBe('https://from-option.example');
});

test('a constant project key wins over a key saved in the backend', function () {
    $wordPress = fakeWordPress();
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_live';
    $wordPress->constants['MONITORING_SECRET'] = 'live-secret';

    $credentials = (new WordPressConfigurationRepository($wordPress))->credentials();

    expect($credentials->projectKey)->toBe('prj_live')
        ->and($credentials->secret)->toBe('live-secret');
});

test('a constant boolean wins over the backend switch', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = ['enabled' => true];
    $wordPress->constants['MONITORING_ENABLED'] = false;

    expect((new WordPressConfigurationRepository($wordPress))->enabled())->toBeFalse();
});

test('lockedBy names the constant or environment variable that overrides a setting', function () {
    $wordPress = new FakeWordPressApi;
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_live';
    $wordPress->envs['MONITORING_SECRET'] = 'live-secret';
    $wordPress->constants['MONITORING_ENDPOINT'] = '';

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->lockedBy('project_key'))->toBe('MONITORING_PROJECT_KEY')
        ->and($repository->lockedBy('secret'))->toBe('MONITORING_SECRET')
        ->and($repository->lockedBy('endpoint'))->toBeNull()
        ->and($repository->lockedBy('enabled'))->toBeNull();
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

test('a false constant does not override a text setting', function () {
    // define('MONITORING_SECRET', getenv('MONITORING_SECRET')) yields false when the variable is unset.
    $wordPress = fakeWordPress();
    $wordPress->constants['MONITORING_PROJECT_KEY'] = false;
    $wordPress->constants['MONITORING_SECRET'] = false;

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->credentials()->projectKey)->toBe('prj_test')
        ->and($repository->credentials()->secret)->toBe('test-secret')
        ->and($repository->lockedBy('project_key'))->toBeNull()
        ->and($repository->lockedBy('secret'))->toBeNull();
});

test('a false constant still switches a setting off', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['enabled'] = true;
    $wordPress->constants['MONITORING_ENABLED'] = false;

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->enabled())->toBeFalse()
        ->and($repository->lockedBy('enabled'))->toBe('MONITORING_ENABLED');
});

test('an environment variable locks a setting like a constant', function () {
    $wordPress = fakeWordPress();
    $wordPress->envs['MONITORING_ENDPOINT'] = 'https://from-env.example';

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->endpoint())->toBe('https://from-env.example')
        ->and($repository->lockedBy('endpoint'))->toBe('MONITORING_ENDPOINT');
});

test('an empty environment variable falls through to the backend', function () {
    $wordPress = fakeWordPress();
    $wordPress->envs['MONITORING_PROJECT_KEY'] = '';

    $repository = new WordPressConfigurationRepository($wordPress);

    expect($repository->credentials()->projectKey)->toBe('prj_test')
        ->and($repository->lockedBy('project_key'))->toBeNull();
});

test('flag reports the switch value in effect', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['enabled'] = true;
    $wordPress->constants['MONITORING_ENABLED'] = 'false';

    expect((new WordPressConfigurationRepository($wordPress))->flag('enabled'))->toBeFalse();
});

test('stored values identical to a constant are removed', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['endpoint'] = 'https://stored.example';
    $wordPress->options['mindtwo_monitoring_settings']['enabled'] = true;
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_test';
    $wordPress->constants['MONITORING_SECRET'] = ' test-secret ';

    $purged = (new WordPressConfigurationRepository($wordPress))->purgeOverriddenValues();

    expect($purged)->toBeTrue()
        ->and($wordPress->options['mindtwo_monitoring_settings'])->toBe([
            'project_key' => '',
            'secret' => '',
            'endpoint' => 'https://stored.example',
            'enabled' => true,
        ]);
});

test('a stored value that differs from the constant is kept', function () {
    // Stale constant or a network-wide constant with per-site keys: deleting would lose data.
    $wordPress = fakeWordPress();
    $wordPress->constants['MONITORING_PROJECT_KEY'] = 'prj_live';
    $wordPress->constants['MONITORING_SECRET'] = 'live-secret';

    $before = $wordPress->options['mindtwo_monitoring_settings'];

    expect((new WordPressConfigurationRepository($wordPress))->purgeOverriddenValues())->toBeFalse()
        ->and($wordPress->options['mindtwo_monitoring_settings'])->toBe($before);
});

test('a value overridden only by an environment variable is kept', function () {
    // Web-server variables (SetEnv, fastcgi_param) are missing in CLI cron, which then needs the stored value.
    $wordPress = fakeWordPress();
    $wordPress->envs['MONITORING_PROJECT_KEY'] = 'prj_test';
    $wordPress->envs['MONITORING_SECRET'] = 'test-secret';

    $before = $wordPress->options['mindtwo_monitoring_settings'];

    expect((new WordPressConfigurationRepository($wordPress))->purgeOverriddenValues())->toBeFalse()
        ->and($wordPress->options['mindtwo_monitoring_settings'])->toBe($before);
});

test('purging leaves the stored settings alone when nothing is overridden', function () {
    $wordPress = fakeWordPress();
    $wordPress->constants['MONITORING_SECRET'] = false;

    $before = $wordPress->options['mindtwo_monitoring_settings'];

    expect((new WordPressConfigurationRepository($wordPress))->purgeOverriddenValues())->toBeFalse()
        ->and($wordPress->options['mindtwo_monitoring_settings'])->toBe($before);
});

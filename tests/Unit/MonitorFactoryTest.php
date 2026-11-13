<?php

declare(strict_types=1);

use Mindtwo\Monitoring\WordPress\MonitorFactory;
use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;

test('the monitor combines the base catalog with the wordpress collectors', function () {
    $wordPress = fakeWordPress();
    $wordPress->absPath = sys_get_temp_dir();

    $monitor = MonitorFactory::make($wordPress);
    $keys = array_keys($monitor->collectors());

    foreach (['os', 'php', 'database', 'composer_packages', 'git'] as $baseKey) {
        expect($keys)->toContain($baseKey);
    }

    foreach (['wordpress', 'wordpress_plugins', 'wordpress_themes', 'wordpress_environment'] as $wordPressKey) {
        expect($keys)->toContain($wordPressKey);
    }
});

test('a snapshot through the factory carries wordpress data and source', function () {
    $wordPress = fakeWordPress();
    $wordPress->absPath = sys_get_temp_dir();
    $wordPress->plugins = ['seo/seo.php' => ['Name' => 'SEO', 'Version' => '1.0']];

    $payload = MonitorFactory::make($wordPress)->snapshot()->toArray();

    expect($payload['source']['type'])->toBe('wordpress')
        ->and($payload['source']['package'])->toBe('mindtwo/wordpress-monitoring')
        ->and($payload['project_key'])->toBe('prj_test')
        ->and($payload['environment'])->toBe('production')
        ->and($payload['metrics']['wordpress']['version'])->toBe('6.5.3')
        ->and($payload['metrics']['wordpress_plugins']['count'])->toBe(1);
});

test('the project root walks up from ABSPATH to find the composer manifest', function () {
    $root = sys_get_temp_dir().'/m2-wp-'.uniqid();
    mkdir($root.'/web/wp', 0755, true);
    file_put_contents($root.'/composer.json', '{}');

    $wordPress = fakeWordPress();
    $wordPress->absPath = $root.'/web/wp/';

    $config = new WordPressConfigurationRepository($wordPress);

    expect(MonitorFactory::projectRoot($wordPress, $config))->toBe($root);

    unlink($root.'/composer.json');
    rmdir($root.'/web/wp');
    rmdir($root.'/web');
    rmdir($root);
});

test('a composer.lock alone identifies the project root', function () {
    // Deploy artifacts that ship the lock file without composer.json still have
    // to be found — composer_audit only needs the lock.
    $root = sys_get_temp_dir().'/m2-wp-'.uniqid();
    mkdir($root.'/web/wp', 0755, true);
    file_put_contents($root.'/composer.lock', '{}');

    $wordPress = fakeWordPress();
    $wordPress->absPath = $root.'/web/wp/';

    expect(MonitorFactory::projectRoot($wordPress, new WordPressConfigurationRepository($wordPress)))->toBe($root);

    unlink($root.'/composer.lock');
    rmdir($root.'/web/wp');
    rmdir($root.'/web');
    rmdir($root);
});

test('an explicitly configured project root wins', function () {
    $wordPress = fakeWordPress();
    $wordPress->options['mindtwo_monitoring_settings']['project_root'] = sys_get_temp_dir();

    $config = new WordPressConfigurationRepository($wordPress);

    expect(MonitorFactory::projectRoot($wordPress, $config))->toBe(sys_get_temp_dir());
});

describe('project root with a composer.lock', function () {
    afterEach(function () {
        if (isset($this->projectTree)) {
            removeTemporaryProjectTree($this->projectTree);
        }
    });

    test('the lock in the project root beats the composer.json of a johnpbloch/wordpress core', function () {
        // johnpbloch/wordpress installs the core into public/wp/ together with
        // its own composer.json, but the project's lock lives two levels up.
        $this->projectTree = temporaryProjectTree([
            'composer.json',
            'composer.lock',
            'public/wp/composer.json',
        ]);

        $wordPress = fakeWordPress();
        $wordPress->absPath = $this->projectTree.'/public/wp/';

        expect(MonitorFactory::projectRoot($wordPress, new WordPressConfigurationRepository($wordPress)))
            ->toBe($this->projectTree);
    });

    test('the nearest composer.lock wins over one further up', function () {
        $this->projectTree = temporaryProjectTree([
            'composer.lock',
            'site/composer.json',
            'site/composer.lock',
        ]);

        $wordPress = fakeWordPress();
        $wordPress->absPath = $this->projectTree.'/site/';

        expect(MonitorFactory::projectRoot($wordPress, new WordPressConfigurationRepository($wordPress)))
            ->toBe($this->projectTree.'/site');
    });

    test('without any composer.lock the nearest composer.json still marks the root', function () {
        $this->projectTree = temporaryProjectTree([
            'composer.json',
            'public/wp/composer.json',
        ]);

        $wordPress = fakeWordPress();
        $wordPress->absPath = $this->projectTree.'/public/wp/';

        expect(MonitorFactory::projectRoot($wordPress, new WordPressConfigurationRepository($wordPress)))
            ->toBe($this->projectTree.'/public/wp');
    });

    test('a configured project root wins over a detected composer.lock', function () {
        $this->projectTree = temporaryProjectTree([
            'composer.json',
            'composer.lock',
            'public/wp/composer.json',
            'elsewhere/composer.json',
        ]);

        $wordPress = fakeWordPress();
        $wordPress->absPath = $this->projectTree.'/public/wp/';
        $wordPress->options['mindtwo_monitoring_settings']['project_root'] = $this->projectTree.'/elsewhere';

        expect(MonitorFactory::projectRoot($wordPress, new WordPressConfigurationRepository($wordPress)))
            ->toBe($this->projectTree.'/elsewhere');
    });
});

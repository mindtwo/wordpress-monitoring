<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Collectors;

use Mindtwo\Monitoring\Collectors\AbstractCollector;
use Mindtwo\Monitoring\Data\CollectionResult;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Operational state of the WordPress installation: environment type, debug
 * flags, multisite, language — the settings worth alerting on in production.
 */
final class WordPressEnvironmentCollector extends AbstractCollector
{
    public function __construct(private WordPressApi $wordPress) {}

    public function key(): string
    {
        return 'wordpress_environment';
    }

    public function collect(): CollectionResult
    {
        return CollectionResult::ok($this->key(), [
            'environment_type' => $this->wordPress->environmentType(),
            'debug' => (bool) $this->wordPress->constant('WP_DEBUG'),
            'debug_display' => (bool) $this->wordPress->constant('WP_DEBUG_DISPLAY'),
            'multisite' => $this->wordPress->isMultisite(),
            'language' => $this->wordPress->language(),
            'timezone' => $this->wordPress->timezone(),
            'site_url' => $this->wordPress->siteUrl(),
        ]);
    }
}

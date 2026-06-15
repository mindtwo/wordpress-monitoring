<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Collectors;

use Mindtwo\Monitoring\Collectors\AbstractCollector;
use Mindtwo\Monitoring\Data\CollectionResult;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Installed WordPress plugins with versions, activity state and available
 * updates — outdated plugins are the number one WordPress security risk.
 */
final class WordPressPluginsCollector extends AbstractCollector
{
    public function __construct(private WordPressApi $wordPress) {}

    public function key(): string
    {
        return 'wordpress_plugins';
    }

    public function collect(): CollectionResult
    {
        $active = array_fill_keys($this->wordPress->activePlugins(), true);
        $updates = $this->wordPress->pluginUpdates();

        $plugins = [];
        $updatesAvailable = 0;

        foreach ($this->wordPress->plugins() as $file => $meta) {
            $updateTo = $updates[$file] ?? null;

            if ($updateTo !== null) {
                $updatesAvailable++;
            }

            $plugins[] = [
                'file' => $file,
                'name' => isset($meta['Name']) && is_string($meta['Name']) ? $meta['Name'] : $file,
                'version' => isset($meta['Version']) && is_string($meta['Version']) ? $meta['Version'] : null,
                'active' => isset($active[$file]),
                'update_available' => $updateTo,
            ];
        }

        $data = [
            'count' => count($plugins),
            'active_count' => count(array_filter($plugins, static fn (array $plugin): bool => (bool) $plugin['active'])),
            'updates_available' => $updatesAvailable,
            'plugins' => $plugins,
        ];

        return $updatesAvailable > 0
            ? CollectionResult::warning($this->key(), $data)
            : CollectionResult::ok($this->key(), $data);
    }
}

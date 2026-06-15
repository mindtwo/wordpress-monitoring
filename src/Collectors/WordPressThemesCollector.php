<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Collectors;

use Mindtwo\Monitoring\Collectors\AbstractCollector;
use Mindtwo\Monitoring\Data\CollectionResult;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Installed themes with versions, the active theme and available updates.
 */
final class WordPressThemesCollector extends AbstractCollector
{
    public function __construct(private WordPressApi $wordPress) {}

    public function key(): string
    {
        return 'wordpress_themes';
    }

    public function collect(): CollectionResult
    {
        $updates = $this->wordPress->themeUpdates();

        $themes = [];
        $updatesAvailable = 0;

        foreach ($this->wordPress->themes() as $stylesheet => $meta) {
            $updateTo = $updates[$stylesheet] ?? null;

            if ($updateTo !== null) {
                $updatesAvailable++;
            }

            $themes[] = [
                'stylesheet' => $stylesheet,
                'name' => isset($meta['name']) && is_string($meta['name']) ? $meta['name'] : $stylesheet,
                'version' => isset($meta['version']) && is_string($meta['version']) ? $meta['version'] : null,
                'active' => (bool) ($meta['active'] ?? false),
                'update_available' => $updateTo,
            ];
        }

        $data = [
            'count' => count($themes),
            'updates_available' => $updatesAvailable,
            'themes' => $themes,
        ];

        return $updatesAvailable > 0
            ? CollectionResult::warning($this->key(), $data)
            : CollectionResult::ok($this->key(), $data);
    }
}

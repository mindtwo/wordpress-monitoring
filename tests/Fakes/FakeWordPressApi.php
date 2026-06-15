<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Tests\Fakes;

use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Array-backed WordPress double: every getter is a public property the test
 * arranges, every mutation is recorded.
 */
final class FakeWordPressApi implements WordPressApi
{
    public ?string $version = '6.5.3';

    /** @var array<string, array<string, mixed>> */
    public array $plugins = [];

    /** @var array<int, string> */
    public array $activePlugins = [];

    /** @var array<string, string> */
    public array $pluginUpdates = [];

    /** @var array<string, array<string, mixed>> */
    public array $themes = [];

    /** @var array<string, string> */
    public array $themeUpdates = [];

    public bool $multisite = false;

    public ?string $environmentType = 'production';

    public ?string $language = 'de_DE';

    public ?string $timezone = 'Europe/Berlin';

    public ?string $siteUrl = 'https://example.com';

    public ?string $absPath = '/var/www/html/';

    /** @var array<string, mixed> */
    public array $options = [];

    /** @var array<string, mixed> */
    public array $constants = [];

    /** @var array<string, string> */
    public array $envs = [];

    /** @var array<string, array{0: mixed, 1: int}> value + ttl */
    public array $transients = [];

    /** @var array<string, int> */
    public array $scheduled = [];

    /** @var array<int, array<string, mixed>> */
    public array $scheduleCalls = [];

    /** @var array<int, string> */
    public array $clearedHooks = [];

    public function version(): ?string
    {
        return $this->version;
    }

    public function plugins(): array
    {
        return $this->plugins;
    }

    public function activePlugins(): array
    {
        return $this->activePlugins;
    }

    public function pluginUpdates(): array
    {
        return $this->pluginUpdates;
    }

    public function themes(): array
    {
        return $this->themes;
    }

    public function themeUpdates(): array
    {
        return $this->themeUpdates;
    }

    public function isMultisite(): bool
    {
        return $this->multisite;
    }

    public function environmentType(): ?string
    {
        return $this->environmentType;
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    public function siteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function absPath(): ?string
    {
        return $this->absPath;
    }

    public function option(string $name, $default = null)
    {
        return $this->options[$name] ?? $default;
    }

    public function updateOption(string $name, $value): void
    {
        $this->options[$name] = $value;
    }

    public function constant(string $name)
    {
        return $this->constants[$name] ?? null;
    }

    public function env(string $name): ?string
    {
        return $this->envs[$name] ?? null;
    }

    public function transient(string $name)
    {
        return $this->transients[$name][0] ?? false;
    }

    public function setTransient(string $name, $value, int $ttlSeconds): void
    {
        $this->transients[$name] = [$value, $ttlSeconds];
    }

    public function deleteTransient(string $name): void
    {
        unset($this->transients[$name]);
    }

    public function nextScheduled(string $hook): ?int
    {
        return $this->scheduled[$hook] ?? null;
    }

    public function scheduleEvent(int $timestamp, string $recurrence, string $hook): void
    {
        $this->scheduled[$hook] = $timestamp;
        $this->scheduleCalls[] = ['timestamp' => $timestamp, 'recurrence' => $recurrence, 'hook' => $hook];
    }

    public function clearScheduledHook(string $hook): void
    {
        unset($this->scheduled[$hook]);
        $this->clearedHooks[] = $hook;
    }
}

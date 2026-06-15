<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\WordPress;

/**
 * Real WordPress implementation. Every call is guarded so the class degrades
 * to empty results instead of fataling when a WordPress function is missing
 * (e.g. during very early bootstrap).
 */
final class NativeWordPressApi implements WordPressApi
{
    public function version(): ?string
    {
        return isset($GLOBALS['wp_version']) ? (string) $GLOBALS['wp_version'] : null;
    }

    public function plugins(): array
    {
        if (! function_exists('get_plugins')) {
            $loader = defined('ABSPATH') ? constant('ABSPATH').'wp-admin/includes/plugin.php' : null;

            if ($loader === null || ! is_readable($loader)) {
                return [];
            }

            require_once $loader;
        }

        if (! function_exists('get_plugins')) {
            return [];
        }

        $plugins = get_plugins();

        return is_array($plugins) ? $plugins : [];
    }

    public function activePlugins(): array
    {
        $active = $this->option('active_plugins', []);
        $active = is_array($active) ? array_values(array_filter($active, 'is_string')) : [];

        $network = $this->isMultisite() && function_exists('get_site_option')
            ? get_site_option('active_sitewide_plugins', [])
            : [];

        if (is_array($network)) {
            $active = array_merge($active, array_map('strval', array_keys($network)));
        }

        return array_values(array_unique($active));
    }

    public function pluginUpdates(): array
    {
        return $this->updatesFromTransient('update_plugins');
    }

    public function themes(): array
    {
        if (! function_exists('wp_get_themes')) {
            return [];
        }

        $current = $this->option('stylesheet');
        $themes = [];

        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[(string) $stylesheet] = [
                'name' => (string) $theme->get('Name'),
                'version' => (string) $theme->get('Version'),
                'active' => $stylesheet === $current,
            ];
        }

        return $themes;
    }

    public function themeUpdates(): array
    {
        return $this->updatesFromTransient('update_themes');
    }

    public function isMultisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public function environmentType(): ?string
    {
        return function_exists('wp_get_environment_type') ? wp_get_environment_type() : null;
    }

    public function language(): ?string
    {
        return function_exists('get_locale') ? get_locale() : null;
    }

    public function timezone(): ?string
    {
        $timezone = $this->option('timezone_string');

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    public function siteUrl(): ?string
    {
        return function_exists('site_url') ? site_url() : null;
    }

    public function absPath(): ?string
    {
        $path = defined('ABSPATH') ? constant('ABSPATH') : null;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function option(string $name, $default = null)
    {
        return function_exists('get_option') ? get_option($name, $default) : $default;
    }

    public function updateOption(string $name, $value): void
    {
        if (function_exists('update_option')) {
            update_option($name, $value);
        }
    }

    public function constant(string $name)
    {
        return defined($name) ? constant($name) : null;
    }

    public function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function transient(string $name)
    {
        return function_exists('get_transient') ? get_transient($name) : false;
    }

    public function setTransient(string $name, $value, int $ttlSeconds): void
    {
        if (function_exists('set_transient')) {
            set_transient($name, $value, $ttlSeconds);
        }
    }

    public function deleteTransient(string $name): void
    {
        if (function_exists('delete_transient')) {
            delete_transient($name);
        }
    }

    public function nextScheduled(string $hook): ?int
    {
        if (! function_exists('wp_next_scheduled')) {
            return null;
        }

        $timestamp = wp_next_scheduled($hook);

        return is_int($timestamp) ? $timestamp : null;
    }

    public function scheduleEvent(int $timestamp, string $recurrence, string $hook): void
    {
        if (function_exists('wp_schedule_event')) {
            wp_schedule_event($timestamp, $recurrence, $hook);
        }
    }

    public function clearScheduledHook(string $hook): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * @return array<string, string>
     */
    private function updatesFromTransient(string $transient): array
    {
        if (! function_exists('get_site_transient')) {
            return [];
        }

        $data = get_site_transient($transient);

        if (! is_object($data) || ! isset($data->response) || ! is_array($data->response)) {
            return [];
        }

        $updates = [];

        foreach ($data->response as $file => $update) {
            $version = null;

            if (is_object($update)) {
                $version = $update->new_version ?? null;
            } elseif (is_array($update)) {
                $version = $update['new_version'] ?? null;
            }

            if (is_string($version) && $version !== '') {
                $updates[(string) $file] = $version;
            }
        }

        return $updates;
    }
}

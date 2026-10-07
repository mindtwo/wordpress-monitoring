<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\WordPress;

/**
 * The thin seam between this plugin and WordPress itself. Everything the
 * collectors, configuration and endpoint logic need from WordPress goes
 * through this interface, so all of it is unit-testable against a fake —
 * no WordPress installation required.
 */
interface WordPressApi
{
    public function version(): ?string;

    /**
     * All installed plugins, keyed by plugin file (as get_plugins()).
     *
     * @return array<string, array<string, mixed>>
     */
    public function plugins(): array;

    /**
     * Plugin files that are currently active (network-wide ones included).
     *
     * @return array<int, string>
     */
    public function activePlugins(): array;

    /**
     * Plugin files with an available update, mapped to the new version.
     *
     * @return array<string, string>
     */
    public function pluginUpdates(): array;

    /**
     * Installed themes: stylesheet => [name, version, active].
     *
     * @return array<string, array<string, mixed>>
     */
    public function themes(): array;

    /**
     * Theme stylesheets with an available update, mapped to the new version.
     *
     * @return array<string, string>
     */
    public function themeUpdates(): array;

    public function isMultisite(): bool;

    public function environmentType(): ?string;

    public function language(): ?string;

    public function timezone(): ?string;

    public function siteUrl(): ?string;

    public function absPath(): ?string;

    /**
     * @param  mixed  $default
     * @return mixed
     */
    public function option(string $name, $default = null);

    /**
     * @param  mixed  $value
     */
    public function updateOption(string $name, $value): void;

    /**
     * A defined PHP constant (wp-config.php style configuration) or null.
     *
     * @return mixed
     */
    public function constant(string $name);

    /**
     * An environment variable or null.
     */
    public function env(string $name): ?string;

    /**
     * @return mixed
     */
    public function transient(string $name);

    /**
     * @param  mixed  $value
     */
    public function setTransient(string $name, $value, int $ttlSeconds): void;

    public function deleteTransient(string $name): void;

    /**
     * Network-wide transient on multisite, a regular transient otherwise.
     *
     * @return mixed
     */
    public function siteTransient(string $name);

    /**
     * @param  mixed  $value
     */
    public function setSiteTransient(string $name, $value, int $ttlSeconds): void;

    public function deleteSiteTransient(string $name): void;

    public function nextScheduled(string $hook): ?int;

    public function scheduleEvent(int $timestamp, string $recurrence, string $hook): void;

    public function clearScheduledHook(string $hook): void;

    /**
     * An HTTP GET through the WordPress HTTP API. Null on transport errors
     * (DNS, TLS, timeout); HTTP error statuses are returned, not swallowed.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string}|null
     */
    public function remoteGet(string $url, array $headers, int $timeoutSeconds): ?array;
}

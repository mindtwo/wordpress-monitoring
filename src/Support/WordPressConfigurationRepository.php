<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Support;

use Mindtwo\Monitoring\Contracts\ConfigurationRepository;
use Mindtwo\Monitoring\Data\Credentials;
use Mindtwo\Monitoring\Transport\HttpTransport;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Configuration with the priority chain MONITORING_* constant (wp-config.php)
 * → environment variable → WordPress backend (options) → secure defaults.
 * Settings live in one option array. Constants and environment variables win
 * because they keep secrets out of the database: a backend value would travel
 * along with every database copy, local ones included.
 */
final class WordPressConfigurationRepository implements ConfigurationRepository
{
    public const OPTION = 'mindtwo_monitoring_settings';

    /** @var array<string, mixed> */
    private const DEFAULTS = [
        'enabled' => true,
        'project_key' => '',
        'secret' => '',
        'endpoint' => HttpTransport::DEFAULT_ENDPOINT,
        'ip_allow_list' => '',
        'route_enabled' => true,
        'schedule_enabled' => true,
        'schedule_recurrence' => 'daily',
        'cache_seconds' => 300,
        'rate_limit_per_minute' => 10,
        'signature_tolerance' => 300,
        'timeout' => 15,
        'project_root' => '',
        'self_update' => true,
    ];

    /** On/off settings: for these a false constant is a real value. */
    private const SWITCHES = ['enabled', 'route_enabled', 'schedule_enabled', 'self_update'];

    /** Stored text settings that are removed once a constant overrides them. */
    private const PURGEABLE = ['project_key', 'secret', 'endpoint', 'ip_allow_list'];

    public function __construct(private WordPressApi $wordPress) {}

    public function credentials(): Credentials
    {
        return new Credentials(
            trim((string) $this->value('project_key', 'MONITORING_PROJECT_KEY')),
            trim((string) $this->value('secret', 'MONITORING_SECRET'))
        );
    }

    public function endpoint(): string
    {
        $endpoint = trim((string) $this->value('endpoint', 'MONITORING_ENDPOINT'));

        return $endpoint !== '' ? $endpoint : HttpTransport::DEFAULT_ENDPOINT;
    }

    /**
     * @return array<int, string>
     */
    public function ipAllowList(): array
    {
        $list = $this->value('ip_allow_list', 'MONITORING_IP_ALLOW_LIST');

        if (is_string($list)) {
            $list = explode(',', $list);
        }

        if (! is_array($list)) {
            return [];
        }

        $entries = [];

        foreach ($list as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $entries[] = trim($entry);
            }
        }

        return $entries;
    }

    /**
     * @param  mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $constant = 'MONITORING_'.strtoupper($key);
        $value = $this->value($key, $constant);

        return $value !== null && $value !== '' ? $value : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    public function enabled(): bool
    {
        return $this->boolean('enabled', 'MONITORING_ENABLED');
    }

    public function routeEnabled(): bool
    {
        return $this->enabled() && $this->boolean('route_enabled', 'MONITORING_ROUTE_ENABLED');
    }

    public function scheduleEnabled(): bool
    {
        return $this->enabled() && $this->boolean('schedule_enabled', 'MONITORING_SCHEDULE_ENABLED');
    }

    /**
     * Update checks for ZIP installs. Deliberately independent of the master
     * switch: pausing monitoring must not cut a site off from plugin fixes.
     */
    public function selfUpdateEnabled(): bool
    {
        return $this->boolean('self_update', 'MONITORING_SELF_UPDATE');
    }

    public function integer(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : (int) (self::DEFAULTS[$key] ?? 0);
    }

    /**
     * The constant or environment variable that overrides a backend setting
     * (MONITORING_ plus the upper-cased key), or null when the backend value
     * applies. The settings screen locks such fields.
     */
    public function lockedBy(string $key): ?string
    {
        $constantName = self::constantName($key);

        return $this->external($constantName, in_array($key, self::SWITCHES, true)) !== null ? $constantName : null;
    }

    /**
     * A switch as it is in effect, constants and environment variables applied.
     */
    public function flag(string $key): bool
    {
        return $this->boolean($key, self::constantName($key));
    }

    /**
     * Whether a stored text value is a mere copy of a wp-config constant and
     * can be removed without losing anything. Only constants count: they are
     * defined in every context, whereas an environment variable set by the web
     * server is missing in CLI cron, which then still needs the stored value.
     * A stored value that differs from the constant is kept for a person to
     * decide on (stale constant, network-wide constant with per-site keys).
     *
     * @param  mixed  $stored
     */
    public function isRedundant(string $key, $stored): bool
    {
        if (! in_array($key, self::PURGEABLE, true) || ! is_string($stored) || trim($stored) === '') {
            return false;
        }

        $constant = $this->wordPress->constant(self::constantName($key));

        return is_string($constant) && trim($constant) !== '' && trim($constant) === trim($stored);
    }

    /**
     * Empties stored keys, secrets and other text settings that are identical
     * to the wp-config constant overriding them, so the database (and every
     * copy of it) no longer carries them. Returns whether anything was removed.
     */
    public function purgeOverriddenValues(): bool
    {
        $options = $this->wordPress->option(self::OPTION, []);

        if (! is_array($options)) {
            return false;
        }

        $changed = false;

        foreach (self::PURGEABLE as $key) {
            if ($this->isRedundant($key, $options[$key] ?? '')) {
                $options[$key] = '';
                $changed = true;
            }
        }

        if ($changed) {
            $this->wordPress->updateOption(self::OPTION, $options);
        }

        return $changed;
    }

    private static function constantName(string $key): string
    {
        return 'MONITORING_'.strtoupper($key);
    }

    /**
     * Constant first, then environment variable, then backend option. Empty
     * strings count as "not configured" so a blank value falls through.
     *
     * @return mixed
     */
    private function value(string $optionKey, string $constantName)
    {
        $external = $this->external($constantName, in_array($optionKey, self::SWITCHES, true));

        if ($external !== null) {
            return $external;
        }

        $options = $this->wordPress->option(self::OPTION, []);

        if (is_array($options) && isset($options[$optionKey]) && $options[$optionKey] !== '') {
            return $options[$optionKey];
        }

        return self::DEFAULTS[$optionKey] ?? null;
    }

    /**
     * The constant, else the environment variable, else null. For anything but
     * a switch a false constant counts as unset: define('X', getenv('X'))
     * yields false when the variable is missing, and must not wipe a stored
     * key. For switches false is a real value (MONITORING_ENABLED = false).
     *
     * @return mixed
     */
    private function external(string $constantName, bool $switch)
    {
        $constant = $this->wordPress->constant($constantName);

        if ($constant !== null && $constant !== '' && ($switch || $constant !== false)) {
            return $constant;
        }

        $env = $this->wordPress->env($constantName);

        return $env !== null && $env !== '' ? $env : null;
    }

    private function boolean(string $optionKey, string $constantName): bool
    {
        $value = $this->value($optionKey, $constantName);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return ! in_array(strtolower($value), ['0', 'false', 'off', 'no', ''], true);
        }

        return (bool) $value;
    }
}

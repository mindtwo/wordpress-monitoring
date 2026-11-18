<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Admin;

use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Minimal settings screen under Settings → Monitoring. Values stored here are
 * the primary configuration source; blank fields fall through to wp-config
 * constants / environment variables and finally to secure defaults.
 */
final class SettingsPage
{
    private const PAGE = 'mindtwo-monitoring';

    private const GROUP = 'mindtwo_monitoring';

    public function __construct(private WordPressApi $wordPress) {}

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_options_page(
                'mindtwo Monitoring',
                'Monitoring',
                'manage_options',
                self::PAGE,
                [$this, 'render']
            );
        });

        add_action('admin_init', function (): void {
            register_setting(self::GROUP, WordPressConfigurationRepository::OPTION, [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize'],
                'default' => [],
            ]);
        });
    }

    /**
     * @param  mixed  $input
     * @return array<string, mixed>
     */
    public function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];

        $current = $this->wordPress->option(WordPressConfigurationRepository::OPTION, []);
        $current = is_array($current) ? $current : [];

        $config = new WordPressConfigurationRepository($this->wordPress);
        $text = static fn (string $key): string => isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

        // Locked text fields are not stored at all, so a constant-backed key or
        // secret leaves the database on the next save and no copy carries it.
        $lockedText = static fn (string $key, string $value): string => $config->lockedBy($key) !== null ? '' : $value;

        // Locked checkboxes are rendered disabled and never submitted; keep what is stored.
        $flag = static fn (string $key): bool => $config->lockedBy($key) !== null
            ? (bool) ($current[$key] ?? true)
            : isset($input[$key]);

        $secret = $text('secret');

        return [
            'enabled' => $flag('enabled'),
            'project_key' => $lockedText('project_key', $text('project_key')),
            // An empty secret field keeps the previously stored secret.
            'secret' => $lockedText('secret', $secret !== '' ? $secret : (string) ($current['secret'] ?? '')),
            'endpoint' => $lockedText('endpoint', $text('endpoint')),
            'ip_allow_list' => $lockedText('ip_allow_list', $text('ip_allow_list')),
            'route_enabled' => $flag('route_enabled'),
            'schedule_enabled' => $flag('schedule_enabled'),
        ];
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $options = $this->wordPress->option(WordPressConfigurationRepository::OPTION, []);
        $options = is_array($options) ? $options : [];

        $option = WordPressConfigurationRepository::OPTION;
        $config = new WordPressConfigurationRepository($this->wordPress);
        $text = static fn (string $key): string => esc_attr((string) ($options[$key] ?? ''));
        $checked = static fn (string $key, bool $default): string => checked((bool) ($options[$key] ?? $default), true, false);

        // A field overridden by a constant or environment variable is shown read-only and empty.
        $lock = static fn (string $key): string => $config->lockedBy($key) !== null ? ' disabled' : '';
        $note = static function (string $key) use ($config): string {
            $constant = $config->lockedBy($key);

            return $constant !== null ? '<p class="description">Set by <code>'.esc_html($constant).'</code> in wp-config.php or the environment.</p>' : '';
        };
        $value = static fn (string $key): string => $config->lockedBy($key) !== null ? '' : $text($key);

        echo '<div class="wrap"><h1>mindtwo Monitoring</h1>';
        echo '<p>Credentials are issued by the central monitoring dashboard. Prefer <code>MONITORING_*</code> constants in wp-config.php (or environment variables): they win over the values below and keep secrets out of the database.</p>';
        echo '<form method="post" action="options.php">';

        settings_fields(self::GROUP);

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="'.esc_attr($option).'[enabled]" value="1" '.$checked('enabled', true).$lock('enabled').'> Collect and report monitoring data</label>'.$note('enabled').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-project-key">Project key</label></th><td><input type="text" class="regular-text" id="m2-project-key" name="'.esc_attr($option).'[project_key]" value="'.$value('project_key').'"'.$lock('project_key').'>'.$note('project_key').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-secret">Secret</label></th><td><input type="password" class="regular-text" id="m2-secret" name="'.esc_attr($option).'[secret]" value="" autocomplete="new-password" placeholder="'.(($options['secret'] ?? '') !== '' && $config->lockedBy('secret') === null ? '••••••••' : '').'"'.$lock('secret').'>'.($note('secret') !== '' ? $note('secret') : '<p class="description">Leave blank to keep the stored secret. Prefer defining <code>MONITORING_SECRET</code> in wp-config.php.</p>').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-endpoint">Endpoint</label></th><td><input type="url" class="regular-text" id="m2-endpoint" name="'.esc_attr($option).'[endpoint]" value="'.$value('endpoint').'" placeholder="https://monitoring.mindtwo.com/api/monitoring"'.$lock('endpoint').'>'.$note('endpoint').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-ips">IP allow-list</label></th><td><input type="text" class="regular-text" id="m2-ips" name="'.esc_attr($option).'[ip_allow_list]" value="'.$value('ip_allow_list').'" placeholder="203.0.113.10, 10.0.0.0/8"'.$lock('ip_allow_list').'>'.($note('ip_allow_list') !== '' ? $note('ip_allow_list') : '<p class="description">Optional, comma-separated IPs or CIDR ranges for the pull endpoint.</p>').'</td></tr>';
        echo '<tr><th scope="row">Pull endpoint</th><td><label><input type="checkbox" name="'.esc_attr($option).'[route_enabled]" value="1" '.$checked('route_enabled', true).$lock('route_enabled').'> Serve snapshots at <code>/'.esc_html(\Mindtwo\Monitoring\WordPress\Plugin::ROUTE).'</code></label>'.$note('route_enabled').'</td></tr>';
        echo '<tr><th scope="row">Scheduled push</th><td><label><input type="checkbox" name="'.esc_attr($option).'[schedule_enabled]" value="1" '.$checked('schedule_enabled', true).$lock('schedule_enabled').'> Push a snapshot daily via WP-Cron</label>'.$note('schedule_enabled').'</td></tr>';
        echo '</table>';

        submit_button();

        echo '</form></div>';
    }
}

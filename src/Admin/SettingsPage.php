<?php

declare(strict_types=1);

namespace Mindtwo\Monitoring\WordPress\Admin;

use Mindtwo\Monitoring\WordPress\Support\WordPressConfigurationRepository;
use Mindtwo\Monitoring\WordPress\WordPress\WordPressApi;

/**
 * Minimal settings screen under Settings → Monitoring. MONITORING_* constants
 * and environment variables win over the values stored here; fields they
 * override are shown read-only and are not stored, so keys and secrets kept in
 * wp-config/.env never land in the database. Blank fields fall through to the
 * secure defaults.
 */
final class SettingsPage
{
    private const PAGE = 'mindtwo-monitoring';

    private const GROUP = 'mindtwo_monitoring';

    private const TEXT_FIELDS = ['project_key', 'secret', 'endpoint', 'ip_allow_list'];

    private const SWITCH_FIELDS = ['enabled', 'route_enabled', 'schedule_enabled'];

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

        $locks = $this->locks();
        $saved = [];

        foreach (self::SWITCH_FIELDS as $key) {
            // Locked checkboxes are rendered disabled and never submitted; keep what is stored.
            // Otherwise read the value, not just its presence: on the first save add_option()
            // runs this callback again on the sanitized array, where an unchecked box is false.
            $saved[$key] = $locks[$key] !== null
                ? (bool) ($current[$key] ?? true)
                : ! empty($input[$key]);
        }

        foreach (self::TEXT_FIELDS as $key) {
            $value = isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';

            if ($key === 'secret' && $value === '') {
                // An empty secret field keeps the previously stored secret.
                $value = (string) ($current['secret'] ?? '');
            }

            // Overridden text fields are not stored, so the next save removes a
            // constant-backed key or secret from the database.
            $saved[$key] = $locks[$key] !== null ? '' : $value;
        }

        return [
            'enabled' => $saved['enabled'],
            'project_key' => $saved['project_key'],
            'secret' => $saved['secret'],
            'endpoint' => $saved['endpoint'],
            'ip_allow_list' => $saved['ip_allow_list'],
            'route_enabled' => $saved['route_enabled'],
            'schedule_enabled' => $saved['schedule_enabled'],
        ];
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $options = $this->wordPress->option(WordPressConfigurationRepository::OPTION, []);
        $options = is_array($options) ? $options : [];

        $config = new WordPressConfigurationRepository($this->wordPress);
        $locks = $this->locks();
        $option = esc_attr(WordPressConfigurationRepository::OPTION);

        $name = static fn (string $key): string => $option.'['.$key.']';
        $lock = static fn (string $key): string => $locks[$key] !== null ? ' disabled' : '';
        $note = static fn (string $key, string $fallback = ''): string => $locks[$key] !== null
            ? '<p class="description">Set by <code>'.esc_html($locks[$key]).'</code> in wp-config.php or the environment.</p>'
            : $fallback;
        $value = static fn (string $key): string => $locks[$key] !== null ? '' : esc_attr((string) ($options[$key] ?? ''));
        $placeholder = static fn (string $key, string $text): string => $locks[$key] !== null ? '' : esc_attr($text);
        // A locked switch shows the value in effect, not the stored one.
        $checked = static fn (string $key): string => checked(
            $locks[$key] !== null ? $config->flag($key) : (bool) ($options[$key] ?? true),
            true,
            false
        );
        $switch = static fn (string $key, string $id, string $label): string => '<label><input type="checkbox" id="'.$id.'" name="'.$name($key).'" value="1"'.$checked($key).$lock($key).'> '.$label.'</label>'.$note($key);

        echo '<div class="wrap"><h1>mindtwo Monitoring</h1>';
        echo '<p>Credentials are issued by the central monitoring dashboard. Prefer <code>MONITORING_*</code> constants in wp-config.php (or environment variables): they win over the values below and keep secrets out of the database.</p>';
        echo '<form method="post" action="options.php">';

        settings_fields(self::GROUP);

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Enabled</th><td>'.$switch('enabled', 'm2-enabled', 'Collect and report monitoring data').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-project-key">Project key</label></th><td><input type="text" class="regular-text" id="m2-project-key" name="'.$name('project_key').'" value="'.$value('project_key').'"'.$lock('project_key').'>'.$note('project_key').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-secret">Secret</label></th><td><input type="password" class="regular-text" id="m2-secret" name="'.$name('secret').'" value="" autocomplete="new-password" placeholder="'.$placeholder('secret', ($options['secret'] ?? '') !== '' ? '••••••••' : '').'"'.$lock('secret').'>'.$note('secret', '<p class="description">Leave blank to keep the stored secret. Prefer defining <code>MONITORING_SECRET</code> in wp-config.php.</p>').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-endpoint">Endpoint</label></th><td><input type="url" class="regular-text" id="m2-endpoint" name="'.$name('endpoint').'" value="'.$value('endpoint').'" placeholder="'.$placeholder('endpoint', 'https://monitoring.mindtwo.com/api/monitoring').'"'.$lock('endpoint').'>'.$note('endpoint').'</td></tr>';
        echo '<tr><th scope="row"><label for="m2-ips">IP allow-list</label></th><td><input type="text" class="regular-text" id="m2-ips" name="'.$name('ip_allow_list').'" value="'.$value('ip_allow_list').'" placeholder="'.$placeholder('ip_allow_list', '203.0.113.10, 10.0.0.0/8').'"'.$lock('ip_allow_list').'>'.$note('ip_allow_list', '<p class="description">Optional, comma-separated IPs or CIDR ranges for the pull endpoint.</p>').'</td></tr>';
        echo '<tr><th scope="row">Pull endpoint</th><td>'.$switch('route_enabled', 'm2-route', 'Serve snapshots at <code>/'.esc_html(\Mindtwo\Monitoring\WordPress\Plugin::ROUTE).'</code>').'</td></tr>';
        echo '<tr><th scope="row">Scheduled push</th><td>'.$switch('schedule_enabled', 'm2-schedule', 'Push a snapshot daily via WP-Cron').'</td></tr>';
        echo '</table>';

        submit_button();

        echo '</form></div>';
    }

    /**
     * Each field's overriding constant or environment variable, or null.
     *
     * @return array<string, string|null>
     */
    private function locks(): array
    {
        $config = new WordPressConfigurationRepository($this->wordPress);
        $locks = [];

        foreach ([...self::SWITCH_FIELDS, ...self::TEXT_FIELDS] as $key) {
            $locks[$key] = $config->lockedBy($key);
        }

        return $locks;
    }
}

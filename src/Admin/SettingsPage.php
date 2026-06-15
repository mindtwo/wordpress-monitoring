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

        $secret = isset($input['secret']) && is_string($input['secret']) ? trim($input['secret']) : '';

        return [
            'enabled' => isset($input['enabled']),
            'project_key' => isset($input['project_key']) && is_string($input['project_key']) ? trim($input['project_key']) : '',
            // An empty secret field keeps the previously stored secret.
            'secret' => $secret !== '' ? $secret : (string) ($current['secret'] ?? ''),
            'endpoint' => isset($input['endpoint']) && is_string($input['endpoint']) ? trim($input['endpoint']) : '',
            'ip_allow_list' => isset($input['ip_allow_list']) && is_string($input['ip_allow_list']) ? trim($input['ip_allow_list']) : '',
            'route_enabled' => isset($input['route_enabled']),
            'schedule_enabled' => isset($input['schedule_enabled']),
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
        $text = static fn (string $key): string => esc_attr((string) ($options[$key] ?? ''));
        $checked = static fn (string $key, bool $default): string => checked((bool) ($options[$key] ?? $default), true, false);

        echo '<div class="wrap"><h1>mindtwo Monitoring</h1>';
        echo '<p>Credentials are issued by the central monitoring dashboard. Values left blank fall back to <code>MONITORING_*</code> constants or environment variables.</p>';
        echo '<form method="post" action="options.php">';

        settings_fields(self::GROUP);

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">Enabled</th><td><label><input type="checkbox" name="'.esc_attr($option).'[enabled]" value="1" '.$checked('enabled', true).'> Collect and report monitoring data</label></td></tr>';
        echo '<tr><th scope="row"><label for="m2-project-key">Project key</label></th><td><input type="text" class="regular-text" id="m2-project-key" name="'.esc_attr($option).'[project_key]" value="'.$text('project_key').'"></td></tr>';
        echo '<tr><th scope="row"><label for="m2-secret">Secret</label></th><td><input type="password" class="regular-text" id="m2-secret" name="'.esc_attr($option).'[secret]" value="" autocomplete="new-password" placeholder="'.(($options['secret'] ?? '') !== '' ? '••••••••' : '').'"><p class="description">Leave blank to keep the stored secret. Prefer defining <code>MONITORING_SECRET</code> in wp-config.php.</p></td></tr>';
        echo '<tr><th scope="row"><label for="m2-endpoint">Endpoint</label></th><td><input type="url" class="regular-text" id="m2-endpoint" name="'.esc_attr($option).'[endpoint]" value="'.$text('endpoint').'" placeholder="https://monitoring.mindtwo.com/api/monitoring"></td></tr>';
        echo '<tr><th scope="row"><label for="m2-ips">IP allow-list</label></th><td><input type="text" class="regular-text" id="m2-ips" name="'.esc_attr($option).'[ip_allow_list]" value="'.$text('ip_allow_list').'" placeholder="203.0.113.10, 10.0.0.0/8"><p class="description">Optional, comma-separated IPs or CIDR ranges for the pull endpoint.</p></td></tr>';
        echo '<tr><th scope="row">Pull endpoint</th><td><label><input type="checkbox" name="'.esc_attr($option).'[route_enabled]" value="1" '.$checked('route_enabled', true).'> Serve snapshots at <code>/'.esc_html(\Mindtwo\Monitoring\WordPress\Plugin::ROUTE).'</code></label></td></tr>';
        echo '<tr><th scope="row">Scheduled push</th><td><label><input type="checkbox" name="'.esc_attr($option).'[schedule_enabled]" value="1" '.$checked('schedule_enabled', true).'> Push a snapshot daily via WP-Cron</label></td></tr>';
        echo '</table>';

        submit_button();

        echo '</form></div>';
    }
}

<?php

declare(strict_types=1);

/*
 * Minimal stand-ins for the WordPress template functions SettingsPage::render()
 * uses, so the rendered HTML can be asserted without a WordPress install.
 */

if (! function_exists('esc_attr')) {
    function esc_attr($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (! function_exists('esc_html')) {
    function esc_html($text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES);
    }
}

if (! function_exists('checked')) {
    function checked($checked, $current = true, $display = true): string
    {
        return (string) $checked === (string) $current ? " checked='checked'" : '';
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (! function_exists('settings_fields')) {
    function settings_fields(string $group): void {}
}

if (! function_exists('submit_button')) {
    function submit_button(): void
    {
        echo '<button type="submit">Save</button>';
    }
}

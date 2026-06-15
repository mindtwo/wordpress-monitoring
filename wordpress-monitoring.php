<?php

declare(strict_types=1);

/**
 * Plugin Name:       mindtwo Monitoring
 * Plugin URI:        https://github.com/mindtwo/wordpress-monitoring
 * Description:       Collects infrastructure, package and security-audit data and reports it to the mindtwo monitoring dashboard — signed, scheduled, and pullable via /api/app-monitoring.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            mindtwo GmbH
 * Author URI:        https://www.mindtwo.de
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       mindtwo-monitoring
 */
if (! defined('ABSPATH')) {
    exit;
}

// Composer-managed installs (Bedrock) autoload globally; classic installs
// bundle the vendor directory inside the plugin.
if (! class_exists(Mindtwo\Monitoring\WordPress\Plugin::class)) {
    $autoloader = __DIR__.'/vendor/autoload.php';

    if (is_readable($autoloader)) {
        require $autoloader;
    }
}

if (class_exists(Mindtwo\Monitoring\WordPress\Plugin::class)) {
    Mindtwo\Monitoring\WordPress\Plugin::boot(__FILE__);
}

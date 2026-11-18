<?php

declare(strict_types=1);

/*
 * Stand-ins for the WordPress HTTP API, so NativeWordPressApi::remotePost() can
 * be asserted without a WordPress install. $GLOBALS['m2_wp_remote_post'] holds
 * the response the stub returns; every call is recorded in
 * $GLOBALS['m2_wp_remote_post_calls'].
 */

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '') {}

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = [])
    {
        $GLOBALS['m2_wp_remote_post_calls'][] = ['url' => $url, 'args' => $args];

        return $GLOBALS['m2_wp_remote_post'];
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing): bool
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return $response['response']['code'] ?? '';
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string
    {
        return $response['body'] ?? '';
    }
}

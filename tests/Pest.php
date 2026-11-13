<?php

declare(strict_types=1);

use Mindtwo\Monitoring\Data\Credentials;
use Mindtwo\Monitoring\Transport\HmacRequestSigner;
use Mindtwo\Monitoring\WordPress\Tests\Fakes\FakeWordPressApi;

function fakeWordPress(): FakeWordPressApi
{
    $wordPress = new FakeWordPressApi;
    $wordPress->options['mindtwo_monitoring_settings'] = [
        'project_key' => 'prj_test',
        'secret' => 'test-secret',
    ];

    return $wordPress;
}

/**
 * Creates a throwaway project tree below the system temp dir and returns its
 * root. Each entry is a file path relative to the root, written as `{}`.
 *
 * @param  list<string>  $files
 */
function temporaryProjectTree(array $files): string
{
    $root = sys_get_temp_dir().'/m2-wp-'.uniqid();
    mkdir($root, 0755, true);

    foreach ($files as $file) {
        $path = $root.'/'.$file;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, '{}');
    }

    return $root;
}

function removeTemporaryProjectTree(string $root): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($root);
}

/**
 * @return array<string, string>
 */
function signedPullHeaders(string $body = '', ?int $timestamp = null): array
{
    $signer = new HmacRequestSigner($timestamp !== null ? static fn (): int => $timestamp : null);

    return $signer->headers($body, new Credentials('prj_test', 'test-secret'));
}

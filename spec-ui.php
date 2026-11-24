<?php

declare(strict_types=1);

const THEME_FILES = [
    'spec-ui.css',
    'assets/spec-mark.png',
    'assets/Inter-Variable.ttf',
    'assets/Inter-OFL.txt',
];

const OWNER_FILE = '.spec-ui-managed';
const OWNER_CONTENT = "Spec UI managed assets\n";
const HOOK_START = '{{-- Spec UI begin --}}';
const HOOK_END = '{{-- Spec UI end --}}';
const LINK_START = '<link rel="stylesheet" href="{{ asset(\'themes/spec-ui/';
const LINK_END = '/spec-ui.css\') }}">';

function readFileContents(string $path): string
{
    if (is_link($path) || !is_file($path) || ($contents = @file_get_contents($path)) === false) {
        throw new RuntimeException("Expected a readable regular file at $path");
    }

    return $contents;
}

function writeFileContents(string $path, string $contents): void
{
    if (@file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException("Could not write $path");
    }
}

function makePublicDirectory(string $path): void
{
    if (!@mkdir($path, 0755) || !@chmod($path, 0755)) {
        throw new RuntimeException("Could not create the public directory $path");
    }
}

function hasMarker(string $dir, string $value): bool
{
    $marker = "$dir/" . OWNER_FILE;

    return !is_link($dir) && is_dir($dir) && !is_link($marker) && is_file($marker)
        && @file_get_contents($marker) === $value;
}

function removeIfEmpty(string $dir): void
{
    if (is_link($dir) || !is_dir($dir)) {
        return;
    }

    $entries = @scandir($dir);
    if ($entries === false) {
        throw new RuntimeException("Could not read $dir");
    }

    if ($entries === ['.', '..', OWNER_FILE] && !@unlink("$dir/" . OWNER_FILE)) {
        throw new RuntimeException("Could not remove the ownership marker in $dir");
    }

    // rmdir leaves any extra files and directories in place.
    if (($entries === ['.', '..'] || $entries === ['.', '..', OWNER_FILE]) && !@rmdir($dir)) {
        throw new RuntimeException("Could not remove $dir");
    }
}

function removeReleaseFiles(string $dir): void
{
    foreach (THEME_FILES as $name) {
        $path = "$dir/$name";
        if (str_starts_with($name, 'assets/') && is_link("$dir/assets")) {
            continue;
        }
        if (!is_link($path) && is_file($path) && !@unlink($path)) {
            throw new RuntimeException("Could not remove $path");
        }
    }

    // Only the release directory itself can contain an ownership marker.
    if (!is_link("$dir/assets") && is_dir("$dir/assets") && @scandir("$dir/assets") === ['.', '..']) {
        if (!@rmdir("$dir/assets")) {
            throw new RuntimeException("Could not remove $dir/assets");
        }
    }
    removeIfEmpty($dir);
}

function publishRelease(string $root): string
{
    $files = [];
    $hash = hash_init('sha256');
    foreach (THEME_FILES as $name) {
        $contents = readFileContents(__DIR__ . "/theme/$name");
        $files[$name] = $contents;
        hash_update($hash, $name . "\0" . hash('sha256', $contents));
    }
    $revision = hash_final($hash);

    $parent = dirname($root);
    if (is_link($parent)) {
        throw new RuntimeException("Expected a regular directory at $parent");
    }
    if (!is_dir($parent)) {
        makePublicDirectory($parent);
    }

    if (!file_exists($root) && !is_link($root)) {
        makePublicDirectory($root);
        writeFileContents("$root/" . OWNER_FILE, OWNER_CONTENT);
    }
    if (!hasMarker($root, OWNER_CONTENT)) {
        throw new RuntimeException("A directory or file at $root belongs to another installation. Move it aside first.");
    }

    $destination = "$root/$revision";
    if (file_exists($destination) || is_link($destination)) {
        if (!hasMarker($destination, "$revision\n") || is_link("$destination/assets")) {
            throw new RuntimeException("Expected a Spec UI release at $destination");
        }
        foreach ($files as $name => $contents) {
            if (readFileContents("$destination/$name") !== $contents) {
                throw new RuntimeException("Published file $destination/$name differs from this release.");
            }
        }

        return $revision;
    }

    // Publish a complete, immutable copy before switching the Blade link.
    $stage = "$root/.stage-" . bin2hex(random_bytes(8));
    makePublicDirectory($stage);
    try {
        makePublicDirectory("$stage/assets");
        foreach ($files as $name => $contents) {
            $path = "$stage/$name";
            writeFileContents($path, $contents);
            if (!@chmod($path, 0644)) {
                throw new RuntimeException("Could not make $path readable by the web server.");
            }
        }
        writeFileContents("$stage/" . OWNER_FILE, "$revision\n");
        if (!@rename($stage, $destination)) {
            throw new RuntimeException("Could not publish the release to $destination");
        }
    } finally {
        if (is_dir($stage)) {
            removeReleaseFiles($stage);
        }
    }

    return $revision;
}

function managedHook(string $view): ?array
{
    $startCount = substr_count($view, HOOK_START);
    $endCount = substr_count($view, HOOK_END);
    if ($startCount === 0 && $endCount === 0) {
        return null;
    }

    $pattern = '~(\r?\n)' . preg_quote(HOOK_START, '~') . '\1'
        . preg_quote(LINK_START, '~') . '[0-9a-f]{64}' . preg_quote(LINK_END, '~')
        . '\1' . preg_quote(HOOK_END, '~') . '\1~';

    if ($startCount !== 1 || $endCount !== 1 || !preg_match($pattern, $view, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('The Spec UI block in layouts/scripts.blade.php has been edited or duplicated.');
    }

    return [$match[0][1], strlen($match[0][0]), $match[1][0]];
}

function removeHook(string $view, ?array $hook): string
{
    if ($hook === null) {
        return $view;
    }

    [$offset, $length, $newline] = $hook;
    $before = substr($view, 0, $offset);
    $after = substr($view, $offset + $length);

    // Keep the boundary between third-party edits made before and after the block.
    if ($before !== '' && $after !== '' && !str_ends_with($before, "\n") && !str_starts_with($after, "\n")) {
        return $before . $newline . $after;
    }

    return $before . $after;
}

function addHook(string $view, ?array $hook, string $revision): string
{
    [$offset, $length, $newline] = $hook ?? [
        strlen($view), 0, str_contains($view, "\r\n") ? "\r\n" : "\n",
    ];
    $block = implode($newline, ['', HOOK_START, LINK_START . $revision . LINK_END, HOOK_END, '']);

    return substr_replace($view, $block, $offset, $length);
}

function replaceView(string $path, string $expected, string $contents): void
{
    $original = @stat($path);
    $temporary = @tempnam(dirname($path), '.spec-ui-');
    if ($temporary === false) {
        throw new RuntimeException("Could not stage a change to $path");
    }

    try {
        if ($original === false || dirname($temporary) !== dirname($path)) {
            throw new RuntimeException("Could not stage a change beside $path");
        }

        writeFileContents($temporary, $contents);
        if ((fileowner($temporary) !== $original['uid'] && !@chown($temporary, $original['uid']))
            || (filegroup($temporary) !== $original['gid'] && !@chgrp($temporary, $original['gid']))
            || !@chmod($temporary, $original['mode'] & 07777)) {
            throw new RuntimeException("Could not preserve the owner and permissions of $path");
        }

        if (readFileContents($path) !== $expected || !@rename($temporary, $path)) {
            throw new RuntimeException("Could not replace $path. Check for other edits and rerun the command.");
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function applyView(string $panel, string $path, string $old, string $new): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('The Panel PHP CLI needs proc_open to clear its compiled views.');
    }

    if ($old !== $new) {
        replaceView($path, $old, $new);
    }

    try {
        $environment = getenv();
        $environment['APP_BASE_PATH'] = $panel;
        $process = @proc_open(
            [PHP_BINARY, 'artisan', 'view:clear', '--no-interaction', '--no-ansi'],
            [STDIN, STDOUT, STDERR],
            $pipes,
            $panel,
            $environment,
        );
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Artisan could not clear the compiled views. Fix the reported error, then rerun Spec UI.');
        }
    } catch (Throwable $error) {
        if ($old !== $new) {
            replaceView($path, $new, $old);
        }
        throw $error;
    }
}

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$usage = "Usage: php spec-ui.php {install|update|uninstall} /path/to/pterodactyl\n";
if ($argc === 2 && $argv[1] === '--help') {
    echo $usage;
    exit(0);
}
if ($argc !== 3 || !in_array($argv[1], ['install', 'update', 'uninstall'], true)) {
    fwrite(STDERR, $usage);
    exit(2);
}

try {
    [$command, $target] = array_slice($argv, 1);
    $panel = realpath($target);
    if ($panel === false || !is_dir($panel)
        || (json_decode(readFileContents("$panel/composer.json"), true)['name'] ?? null) !== 'pterodactyl/panel') {
        throw new RuntimeException('Pass the root directory of a Pterodactyl Panel installation.');
    }

    $layouts = "$panel/resources/views/layouts";
    $public = "$panel/public";
    if (realpath($layouts) !== $layouts || realpath($public) !== $public) {
        throw new RuntimeException('Expected local Panel views and public directories.');
    }
    $lock = @fopen($layouts, 'rb');
    if ($lock === false || !@flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Could not lock the Panel views. Wait for other theme operations to finish and retry.');
    }

    $path = "$layouts/scripts.blade.php";
    $view = readFileContents($path);
    $hook = managedHook($view);
    $root = "$public/themes/spec-ui";
    $ownsAssets = !is_link("$public/themes") && hasMarker($root, OWNER_CONTENT);

    if ($command === 'uninstall' && $hook === null && !$ownsAssets) {
        echo "There is no script-managed Spec UI installation here.\n";
        exit(0);
    }

    $withoutHook = removeHook($view, $hook);
    $core = readFileContents("$panel/resources/views/templates/base/core.blade.php");
    $wrapper = readFileContents("$panel/resources/views/templates/wrapper.blade.php");
    if (str_contains($withoutHook . $core . $wrapper, 'themes/spec-ui/')) {
        throw new RuntimeException('A manual Spec UI link is present in a Panel template. Remove that link first.');
    }
    if (!is_file("$panel/artisan") || !is_file("$panel/vendor/autoload.php")) {
        throw new RuntimeException('Expected a configured Panel with its PHP dependencies installed.');
    }

    if ($command === 'uninstall') {
        applyView($panel, $path, $view, $withoutHook);

        if ($ownsAssets) {
            foreach (new DirectoryIterator($root) as $entry) {
                $revision = $entry->getFilename();
                if (preg_match('/^[0-9a-f]{64}$/D', $revision) && hasMarker($entry->getPathname(), "$revision\n")) {
                    removeReleaseFiles($entry->getPathname());
                }
            }
            removeIfEmpty($root);
        }
        echo "Spec UI uninstalled.\n";
    } else {
        if (!str_contains($core, "@extends('templates/wrapper'")
            || !str_contains($wrapper, "@include('layouts.scripts')")
            || !is_file("$public/assets/manifest.json")) {
            throw new RuntimeException('Expected the Pterodactyl 1.x Blade include and shipped frontend assets.');
        }

        $revision = publishRelease($root);
        applyView($panel, $path, $view, addHook($view, $hook, $revision));
        echo $command === 'install' ? "Spec UI installed.\n" : "Spec UI updated.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Spec UI: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($lock) && is_resource($lock)) {
        fclose($lock);
    }
}

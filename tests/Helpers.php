<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\IO\BufferIO;
use Composer\Script\Event;

/**
 * Absolute path to the canonical package policy (resources/policy.toml,
 * published via the `gaze-policy` tag).
 *
 * A missing gaze BINARY is a legitimate reason to skip integration tests;
 * a missing policy FIXTURE is not — it means the suite itself is broken
 * (this happened when policy.toml.example was moved to resources/policy.toml
 * and the integration tests kept pointing at the dead path). Fail loudly.
 */
function gl_integrationPolicyPath(): string
{
    $path = dirname(__DIR__).'/resources/policy.toml';

    if (! is_file($path)) {
        throw new RuntimeException(
            "Integration policy fixture missing: {$path}. The canonical policy is ".
            'resources/policy.toml (published via the gaze-policy tag). If it moved, '.
            'update gl_integrationPolicyPath(); do not let tests silently skip or '.
            'point at a dead path.'
        );
    }

    return $path;
}

function gl_makeExecutable(string $dir, string $name): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/bin/sh\necho stub\n");
    chmod($path, 0755);

    return $path;
}

function gl_recursiveRemove(string $dir): void
{
    if (! is_dir($dir)) {
        @unlink($dir);

        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;
        is_dir($path) ? gl_recursiveRemove($path) : @unlink($path);
    }

    @rmdir($dir);
}

function gl_makeEvent(BufferIO $io, string $binDir): Event
{
    $config = new Config(false);
    $config->merge(['config' => ['bin-dir' => $binDir]]);

    $composer = new Composer;
    $composer->setConfig($config);

    return new Event('post-install-cmd', $composer, $io);
}

function gl_makeProcessFixture(string $dir, string $name, string $phpBody): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, "#!/usr/bin/env php\n<?php\n{$phpBody}\n");
    chmod($path, 0755);

    return $path;
}

function gl_nerChecksumFixture(): string
{
    return implode("\n", [
        '1213fdd405d295768b0d41d8214062f2f278f0e3acff6af67d8fd47360d2be0f  model.onnx',
        'bf1b59b7b11c95f194f51708d918eea378e09d05f84c0e1656dc5180e8117088  tokenizer.json',
        '470cff6e0353b08e2a6e9b4f61729ecdc47ccb3ced335fa5520e9ce334572d59  tokenizer_config.json',
        '8e5caefadaf9923a9e7d3de42ca97780c68fc4d83519d333f141b299e40af638  config.json',
        'b6d346be366a7d1d48332dbc9fdf3bf8960b5d879522b7799ddba59e76237ee3  special_tokens_map.json',
        'fe0fda7c425b48c516fc8f160d594c8022a0808447475c1a7c6d6479763f310c  vocab.txt',
        '8498e2bafc017a793571c3c2f7092390a93a757f5ca45004f21db2560a8c6fdb  labels.json',
    ]);
}

/**
 * Open a writable in-memory stream pre-seeded with the given content.
 * Used by the daemon unit tests so PHPStan sees a non-false resource.
 *
 * @return resource
 */
function gl_memoryStream(string $initial = '')
{
    $handle = fopen('php://temp', 'w+');
    if (! is_resource($handle)) {
        throw new RuntimeException('failed to open php://temp');
    }

    if ($initial !== '') {
        fwrite($handle, $initial);
        rewind($handle);
    }

    return $handle;
}

/**
 * Encode a value as JSON, asserting success so PHPStan narrows away the
 * `false` branch in test-side fixtures.
 */
function gl_jsonEncode(mixed $value, int $flags = 0): string
{
    $encoded = json_encode($value, $flags);
    if ($encoded === false) {
        throw new RuntimeException('failed to encode JSON fixture');
    }

    return $encoded;
}

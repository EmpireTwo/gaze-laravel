<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Concerns;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Shared helpers for artisan commands that assemble argv for the upstream
 * `gaze` binary from config keys + artisan options.
 *
 * Extracted from the proxy / daemon / install command families, which each
 * carried a private copy. Semantics are identical everywhere: empty strings
 * are treated as absent, and flags render as `--name=value`.
 */
trait BuildsBinaryArgv
{
    /**
     * Append `--name=value` when value is a non-empty string.
     *
     * @param  list<string>  $argv
     */
    protected function appendFlag(array &$argv, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $argv[] = "--{$name}={$value}";
        }
    }

    /**
     * Artisan option as non-empty string, or null.
     */
    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Config value as non-empty string, or null.
     */
    protected function configString(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Config value cast to string when numeric, or null.
     */
    protected function configNumericString(ConfigRepository $config, string $key): ?string
    {
        $value = $config->get($key);

        return is_numeric($value) ? (string) $value : null;
    }
}

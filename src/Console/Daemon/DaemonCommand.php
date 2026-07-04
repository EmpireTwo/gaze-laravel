<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Daemon;

use CertaMesh\Gaze\BinaryResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Base for the gaze:daemon:* artisan wrappers.
 *
 * The upstream `gaze daemon` binary is a foreground stdio worker — no
 * subverbs (unlike `gaze proxy`). Subclasses own the verb-specific flag
 * list that's appended after `gaze daemon`.
 *
 * Supervision is OS-owned (systemd / Horizon process / supervisord). The
 * adapter ships only `:serve` (foreground exec wrapper) and `:status`
 * (best-effort PID lookup with explicit caveat). `:start`, `:stop`,
 * `:restart`, `:logs` are intentionally absent — adopters use their own
 * supervisor's primitives.
 */
abstract class DaemonCommand extends Command
{
    /**
     * Verb-specific flag list assembled from config + artisan options.
     *
     * @return list<string>
     */
    abstract protected function flags(ConfigRepository $config): array;

    /**
     * Build full argv: `[binary, daemon, ...flags]`.
     *
     * @return list<string>
     */
    public function buildArgv(BinaryResolver $resolver, ConfigRepository $config): array
    {
        $binaryOverride = $config->get('gaze.daemon.binary_path');
        $binary = is_string($binaryOverride) && $binaryOverride !== ''
            ? $binaryOverride
            : $resolver->resolve();

        $argv = [$binary, 'daemon'];

        foreach ($this->flags($config) as $flag) {
            $argv[] = $flag;
        }

        return $argv;
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

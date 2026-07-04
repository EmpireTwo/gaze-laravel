<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ProxyStatusCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:status';

    protected $description = 'Check whether the gaze-proxy daemon is running. Exits 0 when running, 1 when stopped.';

    protected function verb(): string
    {
        return 'status';
    }

    protected function flags(ConfigRepository $config): array
    {
        return [];
    }

    /**
     * Upstream `gaze proxy status` always exits 0 and prints `gaze-proxy
     * running (...)` or `gaze-proxy not running`. We translate the latter to
     * a non-zero exit so CI / Sentry can probe the daemon directly.
     */
    protected function successExitCode(string $stdout): int
    {
        return str_contains($stdout, 'not running') ? self::FAILURE : self::SUCCESS;
    }
}

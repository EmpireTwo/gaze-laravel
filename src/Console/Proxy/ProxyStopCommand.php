<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ProxyStopCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:stop
        {--force : Force-kill (SIGKILL) if graceful stop times out}
        {--timeout= : Override gaze.proxy.stop_timeout (e.g. 30s)}';

    protected $description = 'Stop the gaze-proxy daemon.';

    protected function verb(): string
    {
        return 'stop';
    }

    protected function flags(ConfigRepository $config): array
    {
        return $this->stopFlags($config);
    }
}

<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Proxy;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class ProxyStartCommand extends ProxyCommand
{
    protected $signature = 'gaze:proxy:start
        {--bind= : Override gaze.proxy.bind (e.g. 127.0.0.1:8787)}
        {--policy= : Override gaze.proxy.policy_path}
        {--rulepack= : Override gaze.proxy.rulepack (default: core)}
        {--session-ttl= : Override gaze.proxy.session_ttl (e.g. 30m)}';

    protected $description = 'Start the gaze-proxy daemon in the background.';

    protected function verb(): string
    {
        return 'start';
    }

    protected function flags(ConfigRepository $config): array
    {
        return $this->launchFlags($config);
    }
}

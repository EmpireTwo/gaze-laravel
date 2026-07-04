<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Proxy;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Base class for the gaze:proxy:* artisan wrappers.
 *
 * Each concrete subcommand shells out to the upstream `gaze proxy <verb>`
 * subcommand via Illuminate\Process\Factory. Subclasses own (a) the `proxy`
 * verb and (b) the verb-specific flag list assembled from config + artisan
 * options.
 *
 * Adopters need the upstream binary built with `cargo install gaze-cli
 * --features proxy` — the GitHub-release binary asset is built without the
 * feature.
 */
abstract class ProxyCommand extends Command
{
    use BuildsBinaryArgv;

    /**
     * The proxy subcommand name (e.g. `start`, `stop`, `serve`).
     */
    abstract protected function verb(): string;

    /**
     * Verb-specific flag list. Items must be `--flag` or `--flag=value`
     * strings ready to forward to the binary.
     *
     * @return list<string>
     */
    abstract protected function flags(ConfigRepository $config): array;

    /**
     * Build the full argv: `[binary, proxy, verb, ...flags]`.
     *
     * @return list<string>
     */
    public function buildArgv(BinaryResolver $resolver, ConfigRepository $config): array
    {
        $argv = [$resolver->resolve(), 'proxy', $this->verb()];

        foreach ($this->flags($config) as $flag) {
            $argv[] = $flag;
        }

        return $argv;
    }

    public function handle(BinaryResolver $resolver, ConfigRepository $config, ProcessFactory $process): int
    {
        try {
            $argv = $this->buildArgv($resolver, $config);
        } catch (GazeBinaryMissingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->runProcess($argv, $config, $process);
    }

    /**
     * Default run strategy: bounded by `gaze.timeout_seconds`, pass through
     * stdout/stderr verbatim, return the binary's exit code.
     *
     * Subclasses that need streaming (`serve`, `logs --follow`) override.
     *
     * @param  list<string>  $argv
     */
    protected function runProcess(array $argv, ConfigRepository $config, ProcessFactory $process): int
    {
        $timeout = (int) $config->get('gaze.timeout_seconds', 30);

        $result = $process
            ->newPendingProcess()
            ->timeout($timeout)
            ->run($argv);

        $stdout = rtrim($result->output());
        if ($stdout !== '') {
            $this->line($stdout);
        }

        if (! $result->successful()) {
            $stderr = rtrim($result->errorOutput());
            if ($stderr !== '') {
                $this->error($stderr);
            }

            return $result->exitCode() ?? self::FAILURE;
        }

        return $this->successExitCode($stdout);
    }

    /**
     * Map a successful binary run to the artisan exit code. Default: pass
     * SUCCESS through. `status` overrides to translate the binary's
     * always-zero exit into a probe-friendly code based on stdout.
     */
    protected function successExitCode(string $stdout): int
    {
        return self::SUCCESS;
    }

    /**
     * Shared flag assembly for the daemon-launching verbs (`start`, `serve`):
     * bind + upstream endpoints + policy/rulepack/session-ttl, each overridable
     * per artisan option where a matching option exists.
     *
     * @return list<string>
     */
    protected function launchFlags(ConfigRepository $config): array
    {
        $argv = [];

        $this->appendFlag(
            $argv,
            'bind',
            $this->stringOption('bind') ?? $this->configString($config, 'gaze.proxy.bind'),
        );
        $this->appendFlag($argv, 'upstream-openai', $this->configString($config, 'gaze.proxy.upstream.openai'));
        $this->appendFlag($argv, 'upstream-anthropic', $this->configString($config, 'gaze.proxy.upstream.anthropic'));
        $this->appendFlag($argv, 'upstream-gemini', $this->configString($config, 'gaze.proxy.upstream.gemini'));
        $this->appendFlag(
            $argv,
            'policy',
            $this->stringOption('policy') ?? $this->configString($config, 'gaze.proxy.policy_path'),
        );
        $this->appendFlag(
            $argv,
            'rulepack',
            $this->stringOption('rulepack') ?? $this->configString($config, 'gaze.proxy.rulepack'),
        );
        $this->appendFlag(
            $argv,
            'session-ttl',
            $this->stringOption('session-ttl') ?? $this->configString($config, 'gaze.proxy.session_ttl'),
        );

        return $argv;
    }

    /**
     * Shared flag assembly for the daemon-stopping verbs (`stop`, `restart`):
     * optional `--force` plus the stop timeout.
     *
     * @return list<string>
     */
    protected function stopFlags(ConfigRepository $config): array
    {
        $argv = [];

        if ((bool) $this->option('force')) {
            $argv[] = '--force';
        }

        $this->appendFlag(
            $argv,
            'timeout',
            $this->stringOption('timeout') ?? $this->configString($config, 'gaze.proxy.stop_timeout'),
        );

        return $argv;
    }

    /**
     * Streaming run: no timeout, pass stdout/stderr chunks through verbatim.
     * Used by `serve` and `logs --follow`.
     *
     * @param  list<string>  $argv
     */
    protected function streamProcess(array $argv, ProcessFactory $process): int
    {
        $result = $process
            ->newPendingProcess()
            ->forever()
            ->run($argv, function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

        return $result->exitCode() ?? self::FAILURE;
    }
}

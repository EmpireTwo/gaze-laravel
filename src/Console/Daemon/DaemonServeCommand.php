<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Daemon;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Daemon\DaemonArgv;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Foreground `gaze daemon` wrapper, suitable for systemd / supervisord /
 * Horizon process-unit semantics. SIGTERM / SIGINT are forwarded to the
 * child via pcntl signal handlers so the supervisor's stop signal reaches
 * the binary's graceful-shutdown loop (spec L29).
 */
final class DaemonServeCommand extends DaemonCommand
{
    protected $signature = 'gaze:daemon:serve
        {--policy= : Override gaze.daemon.policy_path}
        {--idle-timeout= : Override gaze.daemon.idle_timeout_s (integer seconds)}
        {--session-idle-timeout= : Override gaze.daemon.session_idle_timeout_s (integer seconds)}
        {--session-cap= : Override gaze.daemon.session_cap (max live sessions before LRU eviction)}
        {--audit-db= : Override gaze.daemon.audit_db_path}
        {--locale= : Override gaze.locale (comma-separated, priority-ordered locale chain)}
        {--ner-threshold= : Override gaze.ner_threshold (0.0-1.0 inclusive)}';

    protected $description = 'Run the gaze-daemon JSONL worker in the foreground (blocks). Use under systemd/Horizon/supervisord.';

    public function handle(BinaryResolver $resolver, ConfigRepository $config, ProcessFactory $process): int
    {
        try {
            $argv = $this->buildArgv($resolver, $config);
        } catch (GazeBinaryMissingException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $invoked = $process->newPendingProcess()->forever()->start($argv, function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        $this->installSignalForwarders($invoked);

        while ($invoked->running()) {
            if (function_exists('pcntl_signal_dispatch')) {
                @pcntl_signal_dispatch();
            }
            usleep(50_000);
        }

        $result = $invoked->wait();

        return $result->exitCode() ?? self::FAILURE;
    }

    /**
     * Delegates the config->flag mapping to {@see DaemonArgv} — the same
     * assembler the provider's `Gaze::daemon()` DaemonClient binding uses —
     * layering only the per-invocation artisan option overrides on top.
     */
    protected function flags(ConfigRepository $config): array
    {
        $overrides = [];
        foreach (['policy', 'idle-timeout', 'session-idle-timeout', 'session-cap', 'audit-db', 'locale', 'ner-threshold'] as $option) {
            $value = $this->stringOption($option);
            if ($value !== null) {
                $overrides[$option] = $value;
            }
        }

        return DaemonArgv::flags($config, $overrides);
    }

    /**
     * Register POSIX signal forwarders so SIGTERM/SIGINT from the supervisor
     * reach the child via Symfony's Process::signal(). No-op on platforms
     * lacking pcntl (Windows, --disable-pcntl builds).
     */
    private function installSignalForwarders(InvokedProcess $invoked): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        $forward = static function (int $signal) use ($invoked): void {
            if ($invoked->running()) {
                $invoked->signal($signal);
            }
        };

        @pcntl_signal(SIGTERM, $forward);
        @pcntl_signal(SIGINT, $forward);
        @pcntl_async_signals(true);
    }
}

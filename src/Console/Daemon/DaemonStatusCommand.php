<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Daemon;

use Illuminate\Console\Command;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Best-effort process discovery for `gaze daemon` workers.
 *
 * The command shells out to a platform-appropriate `pgrep -f "gaze daemon"`
 * and reports each PID + cmdline it finds. This is intentionally narrow —
 * supervision is
 * OS-owned, so a daemon launched by systemd / Horizon may not be visible
 * to this command's process scope, and a daemon launched outside the
 * wrapper inherits unrelated cmdlines. The help text states this
 * explicitly so adopters know to query their supervisor for ground truth.
 */
final class DaemonStatusCommand extends Command
{
    protected $signature = 'gaze:daemon:status';

    protected $description = 'Best-effort: list locally visible `gaze daemon` processes. NOT a supervisor — see help.';

    protected $help = <<<'TEXT'
Reports `gaze daemon` PIDs visible to the current user. This command is a
diagnostic, not a supervisor:

  • A daemon launched by systemd / supervisord / Horizon under a different
    UID is NOT reported.
  • A `gaze:daemon:serve` wrapper that was killed by your supervisor between
    invocations is also NOT reported.

For ground truth, query your supervisor:
  systemd  → `systemctl status gaze-daemon`
  Horizon  → `php artisan horizon:status` and your process configuration
  Forge    → the daemon list in the dashboard

This command exits 0 when at least one PID is found, 1 otherwise.
TEXT;

    public function handle(ProcessFactory $process): int
    {
        $result = $process->newPendingProcess()
            ->timeout(2)
            ->run(self::discoveryCommand(PHP_OS_FAMILY));

        /** @var list<array{string, string}> $rows */
        $rows = [];
        foreach (preg_split('/\r?\n/', trim($result->output())) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$pid, $cmd] = array_pad(explode(' ', $line, 2), 2, '');
            if ($pid === (string) getmypid()) {
                continue; // pgrep -f can self-match this artisan process; never report ourselves.
            }
            $rows[] = [$pid, $cmd];
        }

        if ($rows === []) {
            $this->components->twoColumnDetail('gaze daemon', '<fg=yellow>no processes found</>');
            $this->line('Supervisor ground truth: systemctl / horizon:status / supervisorctl.');

            return self::FAILURE;
        }

        foreach ($rows as [$pid, $cmd]) {
            $this->components->twoColumnDetail($pid, $cmd === '' ? '<fg=red>unknown</>' : $cmd);
        }

        return self::SUCCESS;
    }

    /**
     * The `pgrep` invocation for the given OS family.
     *
     * Linux (procps) pairs `-f` with `-a` to print the full command line next
     * to each PID. On macOS/BSD, `-a` instead means "include ancestors" and
     * the output carries bare PIDs only — every row would render as
     * "unknown" — so the BSD spelling `-l` is used to emit `<pid> <cmdline>`.
     *
     * @return list<string>
     */
    public static function discoveryCommand(string $osFamily): array
    {
        return $osFamily === 'Darwin'
            ? ['pgrep', '-fl', 'gaze daemon']
            : ['pgrep', '-af', 'gaze daemon'];
    }
}

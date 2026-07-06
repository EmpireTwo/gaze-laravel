<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Concerns;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * Shared health probes for `gaze:check` and `gaze:doctor`.
 *
 * `gaze:check` is a strict subset of `gaze:doctor` — binary resolution,
 * `--version` runnability and encrypter construction. Both commands
 * delegate to these probes so the checks cannot drift; each command keeps
 * ownership of any output that differs between the two (the feature tests
 * pin the exact text).
 */
trait RunsHealthProbes
{
    /**
     * Resolve the gaze binary. On failure prints the shared
     * binary-missing FAIL block and returns null.
     */
    protected function probeBinary(BinaryResolver $resolver): ?string
    {
        try {
            $binary = $resolver->resolve();
        } catch (GazeBinaryMissingException $e) {
            $this->components->twoColumnDetail('binary', '<fg=red>missing</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            $this->line($e->getMessage());

            return null;
        }

        $this->components->twoColumnDetail('binary', $binary);

        return $binary;
    }

    /**
     * Run `gaze --version`. On success prints the version detail and returns
     * the raw stdout; on failure prints the shared FAIL block (plus the
     * command-specific note, when given) and returns null.
     */
    protected function probeVersion(string $binary, ProcessFactory $process, ?string $failureNote = null): ?string
    {
        $result = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);

        if (! $result->successful()) {
            $this->components->twoColumnDetail('version', '<fg=red>unknown</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            if ($failureNote !== null) {
                $this->line($failureNote);
            }

            return null;
        }

        $this->components->twoColumnDetail('version', trim($result->output()));

        return $result->output();
    }

    /**
     * Construct the `gaze.encrypter` binding. Returns the failure, or null
     * when the encrypter resolves cleanly. Output is left to the caller —
     * check and doctor render the failure differently.
     */
    protected function probeEncrypter(): ?\Throwable
    {
        try {
            $this->laravel->make('gaze.encrypter');

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }
}

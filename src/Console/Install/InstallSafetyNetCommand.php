<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv;
use CertaMesh\Gaze\Install\KijiArtifacts;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use CertaMesh\Gaze\Install\SafetyNetConfiguratorResult;
use Illuminate\Console\Command;

/**
 * Wire a gaze safety-net backend into `.env` idempotently.
 *
 *   - `opf`  (Tier 2, openai-filter) — a LOCAL subprocess; we wire the optional
 *     command/checkpoint paths and warn that doctor cannot verify the subprocess.
 *   - `kiji` (Tier 2.5, kiji-distilbert) — validated BEFORE the write (CB4):
 *     the pinned model dir must already carry the upstream-fetched artifacts, so
 *     a fresh-process `gaze:doctor` never hard-REDs on a wiring we just wrote.
 *
 * Kiji model artifacts remain upstream-provided (scripts/fetch-kiji-safetynet-model.sh).
 */
final class InstallSafetyNetCommand extends Command
{
    use BuildsBinaryArgv;

    protected $signature = 'gaze:install:safety-net
        {--safety-net= : Backend to wire non-interactively: opf|kiji}
        {--kiji-model-dir= : Pinned Kiji DistilBERT model directory (kiji backend only)}
        {--opf-command= : Path to the local opf subprocess binary (opf backend only)}
        {--opf-checkpoint= : opf model checkpoint directory (opf backend only)}
        {--force : Overwrite existing safety-net env keys}
        {--print : Print the env lines instead of writing .env}';

    protected $description = 'Wire a gaze safety-net backend (opf | kiji) into .env idempotently.';

    public function handle(SafetyNetConfigurator $configurator): int
    {
        $backend = $this->resolveBackend();
        if ($backend === null) {
            return self::FAILURE;
        }
        if (! in_array($backend, ['opf', 'kiji'], true)) {
            $this->error("unknown safety-net backend '{$backend}'; expected opf or kiji");

            return self::INVALID;
        }

        $modelDir = null;
        if ($backend === 'kiji') {
            $modelDir = $this->resolveKijiModelDir();
            if (! $this->kijiArtifactsReady($modelDir)) {
                return self::FAILURE; // CB4: never write a .env doctor would reject
            }
        }

        $pairs = SafetyNetConfigurator::pairsFor(
            $backend,
            $modelDir,
            $backend === 'opf' ? $this->stringOption('opf-command') : null,
            $backend === 'opf' ? $this->stringOption('opf-checkpoint') : null,
        );

        if ((bool) $this->option('print')) {
            $this->line($configurator->preview($pairs));

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $result = null;

        if ($this->progressEnabled()) {
            // No network fetch here — the kiji model artifacts are upstream-provided;
            // this step only wires .env. The spinner reports the setup honestly
            // and surfaces DONE/FAIL without swallowing the guidance lines below.
            $this->components->task(
                "Wiring safety-net backend ({$backend})",
                function () use ($configurator, $pairs, $force, &$result): bool {
                    $result = $configurator->apply($pairs, force: $force);

                    return true;
                },
            );
        } else {
            $result = $configurator->apply($pairs, force: $force);
        }

        if (! $result instanceof SafetyNetConfiguratorResult) {
            return self::FAILURE;
        }

        $result->status === 'unchanged'
            ? $this->components->info("safety-net already wired ({$backend}); no change")
            : $this->components->info("safety-net wired ({$backend}) → {$result->envPath}");

        $this->components->warn('run `php artisan config:clear` so the new .env values take effect.');

        if ($backend === 'opf') {
            // CB4: doctor has no Laravel-side probe for the opf subprocess.
            $this->components->warn(
                'opf subprocess is local — install the opf binary + checkpoint yourself '
                .'(GAZE_OPENAI_FILTER_COMMAND / GAZE_OPENAI_FILTER_CHECKPOINT); '
                .'gaze:doctor cannot verify the opf subprocess.'
            );
        }

        return self::SUCCESS;
    }

    private function resolveBackend(): ?string
    {
        $flag = $this->stringOption('safety-net');
        if ($flag !== null) {
            return $flag;
        }

        if (! $this->input->isInteractive()) {
            $this->error('non-interactive; pass --safety-net=opf|kiji');

            return null;
        }

        $choice = $this->choice('Which safety-net backend?', [
            'opf' => 'OpenAI privacy-filter (Tier 2)',
            'kiji' => 'Kiji DistilBERT NER (Tier 2.5)',
        ], 'opf');

        return is_string($choice) ? $choice : 'opf';
    }

    private function resolveKijiModelDir(): ?string
    {
        $dir = $this->stringOption('kiji-model-dir');
        if ($dir !== null) {
            return $dir;
        }

        if ($this->input->isInteractive()) {
            $answer = $this->ask('Kiji DistilBERT model directory (from scripts/fetch-kiji-safetynet-model.sh)');

            return is_string($answer) && $answer !== '' ? $answer : null;
        }

        return null;
    }

    /** CB4: mirror doctor's probeKijiArtifacts BEFORE writing .env. */
    private function kijiArtifactsReady(?string $modelDir): bool
    {
        $missing = KijiArtifacts::missing($modelDir);
        if ($missing === []) {
            return true;
        }

        $this->error(
            $modelDir === null || $modelDir === ''
                ? 'kiji backend requires --kiji-model-dir pointing at the pinned model directory. '
                    .'Fetch it with upstream scripts/fetch-kiji-safetynet-model.sh.'
                : 'kiji model dir is missing required artifacts ('.implode(', ', $missing).'). '
                    .'Re-fetch with upstream scripts/fetch-kiji-safetynet-model.sh (dir 0o700, files 0o600).'
        );

        return false;
    }

    /**
     * Show a live spinner only on an interactive, decorated TTY; CI, piped
     * output and `--no-interaction` keep the plain immediate output.
     */
    private function progressEnabled(): bool
    {
        return $this->output->isDecorated() && $this->input->isInteractive();
    }
}

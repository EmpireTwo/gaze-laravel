<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;

/**
 * One-shot umbrella that provisions a Laravel app to use gaze end-to-end —
 * binary, config, policy, NER model, safety-net backend — idempotently, then
 * gates on `gaze:doctor`.
 *
 * Composition is via `$this->call()` over the thin sub-commands. Three
 * correctness guarantees per the counselor review:
 *   - CB3: the NER step forwards the confirm-only `--yes` headless (never the
 *     overloaded `--force`), so CI re-runs don't re-fetch the 184MB model or
 *     clobber a hand-edited policy.toml [ner].
 *   - CB4: the final `gaze:doctor` gate runs as a real subprocess so it re-reads
 *     the freshly written `.env` instead of stale boot-time config.
 *   - CB6: a failed run rolls `.env` back to its pre-install state.
 */
final class InstallCommand extends Command
{
    use BuildsBinaryArgv;

    protected $signature = 'gaze:install
        {--force : Re-run already-done steps (binary re-download, NER re-fetch, config re-publish). Does NOT overwrite an existing policy.toml.}
        {--force-policy : Also overwrite an existing policy.toml (destructive — off by default for reversibility)}
        {--skip-binary : Do not install the gaze binary}
        {--skip-ner : Do not install the NER model (~184 MB)}
        {--skip-safety-net : Do not configure a safety-net backend}
        {--safety-net= : Safety-net backend non-interactively: opf|kiji|none}
        {--kiji-model-dir= : Kiji DistilBERT model dir forwarded to gaze:install:safety-net}
        {--ner-variant=int8 : NER quantization variant forwarded to gaze:install:ner}
        {--ner-locale= : BCP47 locale forwarded to gaze:install:ner}
        {--no-doctor : Skip the final gaze:doctor green-check}';

    protected $description = 'Provision this app to use gaze end-to-end (binary, config, policy, NER, safety-net), ending on gaze:doctor.';

    public function handle(Application $app, ProcessFactory $process, SafetyNetConfigurator $configurator): int
    {
        $force = (bool) $this->option('force');
        $headless = ! $this->input->isInteractive();

        /** @var array<string, string> $summary */
        $summary = [];
        $ok = true;

        // CB6: snapshot .env so a failed run can be restored to its pristine state.
        $envPath = $configurator->envPath();
        $envSnapshot = is_file($envPath) ? (string) file_get_contents($envPath) : null;

        // 1. binary
        if ($this->option('skip-binary')) {
            $summary['binary'] = 'SKIP';
        } else {
            $code = $this->call('gaze:install:binary', $force ? ['--force' => true] : []);
            $summary['binary'] = $code === 0 ? 'OK' : 'FAIL';
            if ($code !== 0) {
                $ok = false;
            }
        }

        // 2. config publish (idempotent; --force re-publishes)
        $this->call('vendor:publish', array_merge(
            ['--tag' => 'gaze-config'],
            $force ? ['--force' => true] : [],
        ));
        $summary['config'] = 'OK';

        // 3. policy publish — never clobber an edited policy.toml without --force-policy (Decision E)
        $policy = $app->basePath('policy.toml');
        if (! is_file($policy) || (bool) $this->option('force-policy')) {
            $this->call('vendor:publish', ['--tag' => 'gaze-policy', '--force' => true]);
            $summary['policy'] = 'OK';
        } else {
            $this->components->info('kept existing policy.toml');
            $summary['policy'] = 'SKIP';
        }

        // 4. NER
        if ($this->option('skip-ner')) {
            $summary['ner'] = 'SKIP';
        } else {
            $nerArgs = ['--variant' => $this->option('ner-variant'), '--update-policy' => true];
            $locale = $this->stringOption('ner-locale');
            if ($locale !== null) {
                $nerArgs['--locale'] = $locale;
            }
            // CB3: confirm-only --yes headless (no re-download); reserve --force for
            // an explicit umbrella --force (re-fetch + policy overwrite).
            if ($force) {
                $nerArgs['--force'] = true;
            } elseif ($headless) {
                $nerArgs['--yes'] = true;
            }
            if ($headless) {
                $nerArgs['--no-interaction'] = true;
            }
            $code = $this->call('gaze:install:ner', $nerArgs);
            $summary['ner'] = $code === 0 ? 'OK' : 'FAIL';
            if ($code !== 0) {
                $ok = false;
            }
        }

        // 5. safety-net
        if ($this->option('skip-safety-net')) {
            $summary['safety-net'] = 'SKIP';
        } else {
            $backend = $this->resolveSafetyNetBackend();
            if ($backend === 'none') {
                $summary['safety-net'] = 'SKIP';
            } else {
                $args = ['--safety-net' => $backend];
                $modelDir = $this->stringOption('kiji-model-dir');
                if ($modelDir !== null) {
                    $args['--kiji-model-dir'] = $modelDir;
                }
                if ($force) {
                    $args['--force'] = true;
                }
                if ($headless) {
                    $args['--no-interaction'] = true;
                }
                $code = $this->call('gaze:install:safety-net', $args);
                $summary['safety-net'] = $code === 0 ? 'OK' : 'FAIL';
                if ($code !== 0) {
                    $ok = false;
                }
            }
        }

        // 6. doctor gate — CB4: real subprocess so it re-reads the written .env.
        if ($this->option('no-doctor')) {
            $summary['doctor'] = 'SKIP';
        } else {
            $doctorOk = $this->runDoctorGate($app, $process);
            $summary['doctor'] = $doctorOk ? 'OK' : 'FAIL';
            if (! $doctorOk) {
                $ok = false;
            }
        }

        // CB6: roll back the .env write on any failure.
        if (! $ok) {
            $this->rollbackEnv($envPath, $envSnapshot);
        }

        $this->renderSummary($summary);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function resolveSafetyNetBackend(): string
    {
        $flag = $this->stringOption('safety-net');
        if ($flag !== null) {
            return $flag;
        }

        if (! $this->input->isInteractive()) {
            return 'none';
        }

        $choice = $this->choice('Configure a safety-net backend?', [
            'none' => 'None',
            'opf' => 'OpenAI privacy-filter (Tier 2)',
            'kiji' => 'Kiji DistilBERT NER (Tier 2.5)',
        ], 'none');

        return is_string($choice) ? $choice : 'none';
    }

    private function runDoctorGate(Application $app, ProcessFactory $process): bool
    {
        // A subprocess boots a fresh kernel that re-reads .env, so a kiji/opf
        // wiring written moments ago is actually reflected (CB4).
        $result = $process->newPendingProcess()
            ->path($app->basePath())
            ->run([PHP_BINARY, $app->basePath('artisan'), 'gaze:doctor']);

        if (! $result->successful()) {
            $this->components->error('gaze:doctor did not pass; fix the reported issue and re-run, or pass --no-doctor to skip the gate.');
        }

        return $result->successful();
    }

    private function rollbackEnv(string $envPath, ?string $snapshot): void
    {
        if ($snapshot === null) {
            if (is_file($envPath)) {
                @unlink($envPath); // .env did not exist before this run
            }

            return;
        }

        if (is_file($envPath) && (string) file_get_contents($envPath) !== $snapshot) {
            file_put_contents($envPath, $snapshot, LOCK_EX);
            $this->components->warn('rolled back .env to its pre-install state after the failed run.');
        }
    }

    /**
     * @param  array<string, string>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->components->info('gaze:install summary');
        foreach ($summary as $step => $status) {
            $color = match ($status) {
                'OK' => 'green',
                'SKIP' => 'yellow',
                default => 'red',
            };
            $this->components->twoColumnDetail($step, "<fg={$color}>{$status}</>");
        }
    }
}

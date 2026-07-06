<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console\Install;

use CertaMesh\Gaze\Install\NerInstaller;
use CertaMesh\Gaze\Install\NerInstallerOptions;
use CertaMesh\Gaze\Install\NerInstallerResult;
use CertaMesh\Gaze\Install\NerInstallException;
use CertaMesh\Gaze\Install\NerInstallStatus;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Symfony\Component\Console\Output\NullOutput;

final class InstallNerCommand extends Command
{
    protected $signature = 'gaze:install:ner
        {--variant=int8 : Quantization variant (int8 only in v0)}
        {--dest= : Override destination dir (default: storage/app/gaze-ner/davlan-mbert-ner-hrl-int8)}
        {--locale= : BCP47 locale hint to embed in [ner] block}
        {--update-policy : Write [ner] block to gaze.policy_path}
        {--force : Confirm install non-interactively AND re-download/overwrite existing destination/policy}
        {--yes : Confirm a headless install without re-downloading or overwriting (distinct from --force)}
        {--check : Verify existing install without downloading}
        {--dry-run : Preview without writing}
        {--no-progress : Suppress progress output}';

    /**
     * @var list<string>
     *
     * `gaze:install-ner` is the pre-PR-2 name, kept as a deprecated alias so the
     * rename stays a MINOR change. Prefer the canonical `gaze:install:ner`.
     *
     * @deprecated the `gaze:install-ner` alias will be removed in a future major; use `gaze:install:ner`
     */
    protected $aliases = ['gaze:install-ner'];

    protected $description = 'Install the pinned ONNX NER model and optionally wire policy.toml (legacy alias: gaze:install-ner).';

    public function handle(NerInstaller $installer, ConfigRepository $config, Application $app): int
    {
        try {
            $options = $this->buildOptions($config, $app);

            if (! $options->dryRun && ! $options->check && ! $this->confirmIfNeeded($options)) {
                $this->warn('aborted');

                return self::FAILURE;
            }

            // --no-progress routes a NullOutput so the fetcher emits no progress
            // line or bar at all. Otherwise the real console output flows down and
            // the fetcher self-gates on isDecorated() (bar on a TTY, plain line in
            // CI), keeping control characters out of non-interactive logs.
            $progressOutput = (bool) $this->option('no-progress')
                ? new NullOutput
                : $this->output->getOutput();

            $result = $installer->install($options, $progressOutput);
            $this->renderResult($result);

            return match ($result->status) {
                NerInstallStatus::Installed,
                NerInstallStatus::AlreadyInstalled,
                NerInstallStatus::CheckPassed,
                NerInstallStatus::DryRun => self::SUCCESS,
                NerInstallStatus::CheckFailed => self::FAILURE,
            };
        } catch (NerInstallException $e) {
            $this->error($e->getMessage());

            return $e->exitCode();
        }
    }

    private function buildOptions(ConfigRepository $config, Application $app): NerInstallerOptions
    {
        $rawVariant = $this->option('variant');
        $variant = is_string($rawVariant) && $rawVariant !== '' ? $rawVariant : 'int8';
        $dest = $this->option('dest');
        if (! is_string($dest) || $dest === '') {
            $dest = $app->storagePath("app/gaze-ner/davlan-mbert-ner-hrl-{$variant}");
        }

        $policyPath = null;
        if ((bool) $this->option('update-policy')) {
            $configured = $config->get('gaze.policy_path');
            $policyPath = is_string($configured) && $configured !== ''
                ? $configured
                : $app->basePath('policy.toml');
        }

        $locale = $this->option('locale');

        return new NerInstallerOptions(
            variant: $variant,
            dest: $dest,
            force: (bool) $this->option('force'),
            check: (bool) $this->option('check'),
            dryRun: (bool) $this->option('dry-run'),
            locale: is_string($locale) && $locale !== '' ? $locale : null,
            policyPath: $policyPath,
            policyForce: (bool) $this->option('force'),
        );
    }

    private function confirmIfNeeded(NerInstallerOptions $options): bool
    {
        // --yes confirms the install without implying re-download/overwrite;
        // --force confirms AND re-downloads/overwrites. The umbrella passes the
        // confirm-only signal so a headless run never re-fetches the 184MB model
        // or clobbers a hand-edited policy.toml [ner] block.
        if ((bool) $this->option('force') || (bool) $this->option('yes')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            $this->error('non-interactive; pass --force or --yes to confirm install');

            return false;
        }

        $this->line("about to download NER model into: {$options->dest}");
        $this->line('approx 184 MB for variant=int8.');

        return $this->confirm('proceed?', true);
    }

    private function renderResult(NerInstallerResult $result): void
    {
        match ($result->status) {
            NerInstallStatus::Installed => $this->info("installed: {$result->dest}"),
            NerInstallStatus::AlreadyInstalled => $this->info("already-installed: {$result->dest}"),
            NerInstallStatus::CheckPassed => $this->info("check-passed: {$result->dest}"),
            NerInstallStatus::CheckFailed => $this->error("check-failed: {$result->dest}"),
            NerInstallStatus::DryRun => $this->comment("dry-run: {$result->dest}"),
        };

        if (! $result->policyWritten && in_array($result->status, [
            NerInstallStatus::Installed,
            NerInstallStatus::AlreadyInstalled,
            NerInstallStatus::DryRun,
        ], true)) {
            $this->newLine();
            $this->line('paste this into your policy.toml or rerun with --update-policy:');
            $this->newLine();
            $this->line($result->policySnippet);
        }
    }
}

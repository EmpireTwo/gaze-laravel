<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Console\Concerns\RunsHealthProbes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Process\Factory as ProcessFactory;

final class CheckCommand extends Command
{
    use RunsHealthProbes;

    protected $signature = 'gaze:check';

    protected $description = 'Verify the gaze binary resolves and is runnable.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process): int
    {
        $binary = $this->probeBinary($resolver);
        if ($binary === null) {
            return self::FAILURE;
        }

        if ($this->probeVersion($binary, $process, 'gaze --version exited non-zero') === null) {
            return self::FAILURE;
        }

        [$encrypterLabel, $encrypterOk] = $this->resolveEncrypterLabel();
        $this->components->twoColumnDetail('encrypter', $encrypterLabel);

        if (! $encrypterOk) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function resolveEncrypterLabel(): array
    {
        $failure = $this->probeEncrypter();
        if ($failure !== null) {
            $this->line($failure->getMessage());

            return ['<fg=red>invalid</>', false];
        }

        /** @var Repository $config */
        $config = $this->laravel->make('config');
        $raw = $config->get('gaze.blob_encryption_key');

        $label = ($raw === null || $raw === '')
            ? 'gaze.encrypter (APP_KEY fallback)'
            : 'gaze.encrypter (dedicated key)';

        return [$label, true];
    }
}

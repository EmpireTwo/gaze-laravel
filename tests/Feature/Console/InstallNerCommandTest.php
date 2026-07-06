<?php

declare(strict_types=1);

use CertaMesh\Gaze\Console\Install\InstallNerCommand;
use CertaMesh\Gaze\Install\NerArtifactSet;
use CertaMesh\Gaze\Install\NerFetcher;
use CertaMesh\Gaze\Install\NerInstaller;
use CertaMesh\Gaze\Install\NerInstallStatus;
use CertaMesh\Gaze\Install\NerManifest;
use CertaMesh\Gaze\Install\PolicyTomlPatcher;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

function gin_command_tester(NerFetcher $fetcher): CommandTester
{
    $installer = new NerInstaller(
        fetcher: $fetcher,
        patcher: new PolicyTomlPatcher,
        manifest: NerManifest::fromString(gl_nerChecksumFixture()),
    );
    app()->instance(NerInstaller::class, $installer);

    $command = app()->make(InstallNerCommand::class);
    $command->setLaravel(app());

    return new CommandTester($command);
}

it('gaze:install:ner exposes the patched flag surface', function () {
    $command = $this->app->make(InstallNerCommand::class);
    $definition = $command->getDefinition();

    foreach (['variant', 'dest', 'update-policy', 'force', 'yes', 'check', 'dry-run', 'no-progress', 'locale'] as $option) {
        expect($definition->hasOption($option))->toBeTrue("--{$option} should exist");
    }

    expect($definition->hasOption('model'))->toBeFalse();
    expect($definition->hasOption('unpinned'))->toBeFalse();
});

it('renames to gaze:install:ner with gaze:install-ner kept as a deprecated alias (Decision D)', function () {
    $command = $this->app->make(InstallNerCommand::class);

    expect($command->getName())->toBe('gaze:install:ner');
    expect($command->getAliases())->toContain('gaze:install-ner');
});

it('still resolves and runs the legacy gaze:install-ner name (CB3 / Decision D)', function () {
    // The deprecated alias must remain functional (MINOR-safe). A --check on a
    // missing dest is a real no-op invocation that proves the name still routes.
    expect(Artisan::all())->toHaveKey('gaze:install-ner');

    $exit = Artisan::call('gaze:install-ner', [
        '--check' => true,
        '--dest' => sys_get_temp_dir().'/gaze-ner-missing-'.bin2hex(random_bytes(4)),
        '--no-progress' => true,
    ]);

    // Missing artifacts → CheckFailed (exit 1); the point is the alias executed.
    expect($exit)->toBe(1);
});

it('--yes confirms a headless install without re-downloading or overwriting (CB3)', function () {
    $fetcher = new class implements NerFetcher
    {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return true;
        }
    };
    $tester = gin_command_tester($fetcher);
    $dest = sys_get_temp_dir().'/gaze-yes-'.bin2hex(random_bytes(6));
    mkdir($dest, 0755, true); // an already-installed destination that verifies

    try {
        // Distinct from --force: --yes confirms the (no-op) install but does NOT
        // re-fetch the 184MB model when the destination already verifies.
        $exit = $tester->execute(['--dest' => $dest, '--yes' => true], ['interactive' => false]);

        expect($exit)->toBe(0);
        expect($fetcher->fetches)->toBe(0);
    } finally {
        @unlink($dest.'/SHA256SUMS');
        @rmdir($dest);
    }
});

it('--variant=bogus exits 2', function () {
    $tester = gin_command_tester(new class implements NerFetcher
    {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void {}

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });

    $exit = $tester->execute(['--variant' => 'bogus', '--check' => true]);

    expect($exit)->toBe(2);
});

it('--check returns failure when artifacts do not verify', function () {
    $tester = gin_command_tester(new class implements NerFetcher
    {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void {}

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });

    $exit = $tester->execute(['--check' => true, '--dest' => sys_get_temp_dir().'/missing-gaze-ner']);

    expect($exit)->toBe(1);
    expect($tester->getDisplay())->toContain(NerInstallStatus::CheckFailed->value);
});

it('prints policy snippet after a successful install when policy is not updated', function () {
    $tester = gin_command_tester(new class implements NerFetcher
    {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });
    $dest = sys_get_temp_dir().'/gaze-command-'.bin2hex(random_bytes(6));

    try {
        $exit = $tester->execute(['--dest' => $dest, '--force' => true, '--locale' => 'de']);

        expect($exit)->toBe(0);
        expect($tester->getDisplay())->toContain('[ner]');
        expect($tester->getDisplay())->toContain('locale = "de"');
    } finally {
        if (is_dir($dest)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dest);
        }
    }
});

it('--update-policy writes the configured policy path', function () {
    $tester = gin_command_tester(new class implements NerFetcher
    {
        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    });
    $dest = sys_get_temp_dir().'/gaze-command-'.bin2hex(random_bytes(6));
    $policy = sys_get_temp_dir().'/gaze-policy-'.bin2hex(random_bytes(6)).'.toml';
    file_put_contents($policy, "[session]\nscope = \"persistent\"\n");
    app('config')->set('gaze.policy_path', $policy);

    try {
        $exit = $tester->execute(['--dest' => $dest, '--force' => true, '--update-policy' => true]);

        expect($exit)->toBe(0);
        expect(file_get_contents($policy))->toContain('[ner]');
        expect(file_get_contents($policy))->toContain($dest);
        expect($tester->getDisplay())->not->toContain('paste this into your policy.toml');
    } finally {
        @unlink($policy);
        @unlink($policy.'.bak');
        if (is_dir($dest)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dest);
        }
    }
});

it('fails non-interactive install without --force before fetching', function () {
    $fetcher = new class implements NerFetcher
    {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    };
    $tester = gin_command_tester($fetcher);

    $exit = $tester->execute(['--dest' => sys_get_temp_dir().'/gaze-no-confirm'], ['interactive' => false]);

    expect($exit)->toBe(1);
    expect($fetcher->fetches)->toBe(0);
    expect($tester->getDisplay())->toContain('pass --force');
});

/** A fetcher that echoes a sentinel to whatever output it is handed. */
function gin_echoingFetcher(string $sentinel): NerFetcher
{
    return new class($sentinel) implements NerFetcher
    {
        public function __construct(private string $sentinel) {}

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $output->writeln($this->sentinel);
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return false;
        }
    };
}

function gin_rmDest(string $dest): void
{
    if (! is_dir($dest)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($dest);
}

it('--no-progress routes a NullOutput so the fetcher emits no progress output', function () {
    $tester = gin_command_tester(gin_echoingFetcher('SENTINEL-PROGRESS-LINE'));
    $dest = sys_get_temp_dir().'/gaze-noprog-'.bin2hex(random_bytes(6));

    try {
        $exit = $tester->execute(
            ['--dest' => $dest, '--force' => true, '--no-progress' => true],
            ['interactive' => false],
        );

        expect($exit)->toBe(0);
        expect($tester->getDisplay())->not->toContain('SENTINEL-PROGRESS-LINE');
    } finally {
        gin_rmDest($dest);
    }
});

it('streams fetcher progress output and stays escape-free when non-interactive', function () {
    $tester = gin_command_tester(gin_echoingFetcher('SENTINEL-PROGRESS-LINE'));
    $dest = sys_get_temp_dir().'/gaze-prog-'.bin2hex(random_bytes(6));

    try {
        $exit = $tester->execute(
            ['--dest' => $dest, '--force' => true],
            ['interactive' => false, 'decorated' => false],
        );

        expect($exit)->toBe(0);
        $display = $tester->getDisplay();
        expect($display)->toContain('SENTINEL-PROGRESS-LINE'); // progress output reaches the user
        expect($display)->not->toContain("\x1b"); // but no ANSI / progress-bar control chars in CI
    } finally {
        gin_rmDest($dest);
    }
});

it('--force fetches even when existing destination verifies', function () {
    $fetcher = new class implements NerFetcher
    {
        public int $fetches = 0;

        public function fetch(NerArtifactSet $set, string $stagingDir, OutputInterface $output): void
        {
            $this->fetches++;
            mkdir($stagingDir, 0755, true);
            foreach ($set->fileNames() as $name) {
                file_put_contents($stagingDir.'/'.$name, $name);
            }
        }

        public function verify(NerArtifactSet $set, string $dir): bool
        {
            return true;
        }
    };
    $tester = gin_command_tester($fetcher);
    $dest = sys_get_temp_dir().'/gaze-force-'.bin2hex(random_bytes(6));

    try {
        $exit = $tester->execute(['--dest' => $dest, '--force' => true]);

        expect($exit)->toBe(0);
        expect($fetcher->fetches)->toBe(1);
    } finally {
        if (is_dir($dest)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($dest);
        }
    }
});

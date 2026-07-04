<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Tests;

use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\GazeOptions;
use CertaMesh\Gaze\GazeServiceProvider;
use CertaMesh\Gaze\GazeSession;
use Illuminate\Foundation\Application;
use Illuminate\Process\Factory as ProcessFactory;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [GazeServiceProvider::class];
    }

    /**
     * @param  list<string>|null  $rulepacks
     * @param  list<string>|null  $rulepackPaths
     */
    public function makeGaze(
        string $explicitPath = '/fake/gaze',
        string $vendorBinPath = '/nonexistent',
        int $timeoutSeconds = 5,
        string $policyPath = '/fake/policy.toml',
        ?int $maxBytes = null,
        ?int $sessionTtlSeconds = null,
        ?string $auditDbPath = null,
        ?string $locale = null,
        ?array $rulepacks = null,
        ?array $rulepackPaths = null,
        bool $safetyNet = false,
        ?string $safetyNetDevice = null,
        ?string $openaiFilterCommand = null,
        ?string $openaiFilterCheckpoint = null,
        ?string $openaiFilterOperatingPoint = null,
        ?int $safetyNetTimeoutMs = null,
        ?int $safetyNetInputLimitBytes = null,
        ?string $safetyNetMode = null,
        ?string $safetyNetBackend = null,
        ?string $kijiBackend = null,
        ?string $kijiDistilbertPrecision = null,
        ?string $kijiDistilbertCommand = null,
        ?string $kijiDistilbertModelDir = null,
        ?string $safetyNetFallback = null,
        ?string $sessionScope = null,
        ?string $restoreMode = null,
        bool $restoreTelemetry = false,
        ?float $nerThreshold = null,
    ): Gaze {
        $app = $this->applicationInstance();

        return new Gaze(
            resolver: new BinaryResolver(
                explicitPath: $explicitPath,
                vendorBinPath: $vendorBinPath,
            ),
            process: $app->make(ProcessFactory::class),
            container: $app,
            policyPath: $policyPath,
            options: new GazeOptions(
                timeoutSeconds: $timeoutSeconds,
                maxBytes: $maxBytes,
                sessionTtlSeconds: $sessionTtlSeconds,
                auditDbPath: $auditDbPath,
                locale: $locale,
                rulepacks: $rulepacks,
                rulepackPaths: $rulepackPaths,
                safetyNet: $safetyNet,
                safetyNetDevice: $safetyNetDevice,
                openaiFilterCommand: $openaiFilterCommand,
                openaiFilterCheckpoint: $openaiFilterCheckpoint,
                openaiFilterOperatingPoint: $openaiFilterOperatingPoint,
                safetyNetTimeoutMs: $safetyNetTimeoutMs,
                safetyNetInputLimitBytes: $safetyNetInputLimitBytes,
                safetyNetMode: $safetyNetMode,
                safetyNetBackend: $safetyNetBackend,
                kijiBackend: $kijiBackend,
                kijiDistilbertPrecision: $kijiDistilbertPrecision,
                kijiDistilbertCommand: $kijiDistilbertCommand,
                kijiDistilbertModelDir: $kijiDistilbertModelDir,
                safetyNetFallback: $safetyNetFallback,
                sessionScope: $sessionScope,
                restoreMode: $restoreMode,
                restoreTelemetry: $restoreTelemetry,
                nerThreshold: $nerThreshold,
            ),
        );
    }

    public function bindScriptedGaze(
        GazeSession $clean,
        ?string $restore = null,
    ): void {
        $stub = new class($clean, $restore) extends Gaze
        {
            public function __construct(
                private readonly GazeSession $clean,
                private readonly ?string $restore,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function clean(string $text, ?float $threshold = null): GazeSession
            {
                return $this->clean;
            }

            public function restore(GazeSession $session, string $text): string
            {
                return $this->restore ?? $text;
            }

            public function audit(?string $auditDbPath = null): AuditService
            {
                throw new \LogicException(
                    'Scripted Gaze stub: audit() is not implemented for this test fixture. Use Gaze::fake() if your test needs to exercise audit verbs.'
                );
            }
        };

        $this->applicationInstance()->instance(Gaze::class, $stub);
    }

    public function bindCountingGaze(
        GazeSession $clean,
        int $expectedCalls,
        ?string $expectedInput = null,
    ): void {
        $stub = new class($clean, $expectedInput) extends Gaze
        {
            public int $calls = 0;

            public function __construct(
                private readonly GazeSession $clean,
                private readonly ?string $expectedInput,
            ) {
                // Skip parent constructor — no process invocations occur.
            }

            public function clean(string $text, ?float $threshold = null): GazeSession
            {
                $this->calls++;

                if ($this->expectedInput !== null) {
                    expect($text)->toBe($this->expectedInput);
                }

                return $this->clean;
            }

            public function restore(GazeSession $session, string $text): string
            {
                return $text;
            }

            public function audit(?string $auditDbPath = null): AuditService
            {
                throw new \LogicException(
                    'Counting Gaze stub: audit() is not implemented for this test fixture. Use Gaze::fake() if your test needs to exercise audit verbs.'
                );
            }
        };

        $this->applicationInstance()->instance(Gaze::class, $stub);
        $this->beforeApplicationDestroyed(function () use ($stub, $expectedCalls): void {
            expect($stub->calls)->toBe($expectedCalls);
        });
    }

    public function bindAndReturnCleanSession(string $cleanText, string $blob, int $detections): GazeSession
    {
        return new GazeSession(
            cleanText: $cleanText,
            ciphertext: EncryptedBlob::wrap($blob),
            detections: $detections,
        );
    }

    private function applicationInstance(): Application
    {
        if (! $this->app instanceof Application) {
            throw new \LogicException('Test application is not initialized.');
        }

        return $this->app;
    }
}

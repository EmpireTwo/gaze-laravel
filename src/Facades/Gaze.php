<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Facades;

use Carbon\CarbonInterface;
use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\Testing\FakeGaze;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @method static \CertaMesh\Gaze\GazeSession clean(string $text, ?float $threshold = null)
 * @method static string mask(string $text, ?callable $replace = null)
 * @method static string restore(\CertaMesh\Gaze\GazeSession $session, string $text)
 * @method static \CertaMesh\Gaze\Contracts\AuditService audit(?string $auditDbPath = null)
 * @method static \CertaMesh\Gaze\Contracts\DaemonManager daemon()
 *
 * Fluent fake configuration — proxied to the FakeGaze root, so these are
 * only available after Gaze::fake() (chaining off the returned fake is the
 * idiomatic form: Gaze::fake()->cleanUsing(...)->failWith(...)):
 * @method static FakeGaze cleanUsing(\Closure $handler)
 * @method static FakeGaze maskUsing(\Closure $handler)
 * @method static FakeGaze restoreUsing(\Closure $handler)
 * @method static FakeGaze failWith(\CertaMesh\Gaze\Exceptions\GazeException $exception)
 * @method static FakeGaze auditPurgeUsing(\Closure $handler)
 * @method static FakeGaze auditExportUsing(\Closure $handler)
 * @method static FakeGaze daemonCleanUsing(\Closure $handler)
 * @method static FakeGaze withAuditRows(list<list<string>> $rows)
 * @method static FakeGaze withSafetyNetRows(list<list<string>> $rows)
 *
 * @see GazeContract
 * @see \CertaMesh\Gaze\Gaze
 */
final class Gaze extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        // The contract is the canonical container binding; the concrete
        // CertaMesh\Gaze\Gaze FQCN is aliased to it, so swapping this
        // accessor (Gaze::fake()) also swaps concrete-name resolution.
        return GazeContract::class;
    }

    /**
     * Swap the bound Gaze service for a FakeGaze and return it so tests can
     * chain assertions. Mirrors Laravel's Queue::fake() / Mail::fake() idiom.
     *
     * @param  \Closure(string, ?float): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     * @param  \Closure(string, bool): AuditPurgeResult|null  $auditPurgeHandler
     * @param  \Closure(string, string): CleanResponse|null  $daemonCleanHandler
     * @param  \Closure(string|null, string): AuditExportResult|null  $auditExportHandler
     */
    public static function fake(
        ?\Closure $cleanHandler = null,
        ?\Closure $restoreHandler = null,
        ?\Closure $auditPurgeHandler = null,
        ?\Closure $daemonCleanHandler = null,
        ?\Closure $auditExportHandler = null,
    ): FakeGaze {
        $fake = new FakeGaze($cleanHandler, $restoreHandler, $auditPurgeHandler, $daemonCleanHandler, $auditExportHandler);
        self::swap($fake);

        return $fake;
    }

    public static function assertCleaned(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->cleanCalls(),
                'Expected Gaze::clean to be called at least once.',
            );

            return;
        }

        $matched = false;
        foreach ($fake->cleanCalls() as $call) {
            if ($call['text'] === $expectedText) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, 'Expected Gaze::clean to be called with given text, but it was not.');
    }

    public static function assertMasked(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->maskCalls(),
                'Expected Gaze::mask to be called at least once.',
            );

            return;
        }

        $matched = false;

        foreach ($fake->maskCalls() as $call) {
            if ($call['text'] === $expectedText) {
                $matched = true;

                break;
            }
        }

        PHPUnit::assertTrue($matched, 'Expected Gaze::mask to be called with given text, but it was not.');
    }

    public static function assertRestored(?string $expectedText = null): void
    {
        $fake = self::requireFake();

        if ($expectedText === null) {
            PHPUnit::assertNotEmpty(
                $fake->restoreCalls(),
                'Expected Gaze::restore to be called at least once.',
            );

            return;
        }

        $matched = false;
        foreach ($fake->restoreCalls() as $call) {
            if ($call['text'] === $expectedText) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, 'Expected Gaze::restore to be called with given text, but it was not.');
    }

    public static function assertCleanCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->cleanCalls(),
            "Expected Gaze::clean to be called {$expected} time(s).",
        );
    }

    public static function assertMaskCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->maskCalls(),
            "Expected Gaze::mask to be called {$expected} time(s).",
        );
    }

    public static function assertRestoreCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->restoreCalls(),
            "Expected Gaze::restore to be called {$expected} time(s).",
        );
    }

    public static function assertAuditPurged(?CarbonInterface $before = null): void
    {
        $fake = self::requireFake();
        $calls = $fake->audit()->purgeCalls();

        if ($before === null) {
            PHPUnit::assertNotEmpty(
                $calls,
                'Expected Gaze::audit()->purge() to be called at least once.',
            );

            return;
        }

        $expected = $before->utc()->toIso8601ZuluString();

        $matched = false;
        foreach ($calls as $call) {
            if ($call['before'] === $expected) {
                $matched = true;
                break;
            }
        }

        PHPUnit::assertTrue($matched, 'Expected Gaze::audit()->purge() to be called with given before timestamp, but it was not.');
    }

    public static function assertAuditPurgeCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->audit()->purgeCalls(),
            "Expected Gaze::audit()->purge() to be called {$expected} time(s).",
        );
    }

    public static function assertNothingCleaned(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->cleanCalls(),
            'Expected Gaze::clean not to be called.',
        );
    }

    public static function assertNothingMasked(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->maskCalls(),
            'Expected Gaze::mask not to be called.',
        );
    }

    public static function assertDaemonCleaned(?string $sessionId = null, ?string $expectedText = null): void
    {
        $fake = self::requireFake();
        $calls = $fake->daemon()->calls();

        if ($sessionId === null && $expectedText === null) {
            PHPUnit::assertNotEmpty(
                $calls,
                'Expected Gaze::daemon()->clean() to be called at least once.',
            );

            return;
        }

        $matched = false;
        foreach ($calls as $call) {
            $sessionMatch = $sessionId === null || $call['session_id'] === $sessionId;
            $textMatch = $expectedText === null || $call['text'] === $expectedText;
            if ($sessionMatch && $textMatch) {
                $matched = true;
                break;
            }
        }

        $criteria = $sessionId !== null ? "session_id={$sessionId}" : 'any session';
        if ($expectedText !== null) {
            $criteria .= " with text={$expectedText}";
        }
        PHPUnit::assertTrue($matched, "Expected Gaze::daemon()->clean() to be called for {$criteria}, but it was not.");
    }

    public static function assertDaemonCleanCount(int $expected): void
    {
        $fake = self::requireFake();

        PHPUnit::assertCount(
            $expected,
            $fake->daemon()->calls(),
            "Expected Gaze::daemon()->clean() to be called {$expected} time(s).",
        );
    }

    public static function assertNothingDaemonCleaned(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->daemon()->calls(),
            'Expected Gaze::daemon()->clean() not to be called.',
        );
    }

    public static function assertAuditExported(?string $path = null): void
    {
        $fake = self::requireFake();
        $calls = $fake->audit()->exportCalls();

        if ($path === null) {
            PHPUnit::assertNotEmpty(
                $calls,
                'Expected Gaze::audit()->query()->export() to be called at least once.',
            );

            return;
        }

        $matched = false;

        foreach ($calls as $call) {
            if ($call['output'] === $path) {
                $matched = true;

                break;
            }
        }

        PHPUnit::assertTrue($matched, 'Expected Gaze::audit()->query()->export() to be called with the given output path, but it was not.');
    }

    public static function assertNothingAudited(): void
    {
        $fake = self::requireFake();

        PHPUnit::assertEmpty(
            $fake->audit()->purgeCalls(),
            'Expected Gaze audit verbs not to be called.',
        );
        PHPUnit::assertEmpty(
            $fake->audit()->exportCalls(),
            'Expected Gaze audit verbs not to be called.',
        );
        PHPUnit::assertEmpty(
            $fake->audit()->safetyNetQueryCalls(),
            'Expected Gaze audit verbs not to be called.',
        );
    }

    private static function requireFake(): FakeGaze
    {
        $resolved = self::getFacadeRoot();

        if (! $resolved instanceof FakeGaze) {
            PHPUnit::fail(
                'Gaze::fake() has not been called. Call Gaze::fake() before asserting.',
            );
        }

        return $resolved;
    }
}

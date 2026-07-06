<?php

declare(strict_types=1);

use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;
use CertaMesh\Gaze\Testing\FakeGaze;

function fluentTestException(): GazeException
{
    return new GazeException('binary exploded', exitCode: 70, stderrHash: 'abc123');
}

it('configures the clean handler fluently and returns the fake for chaining', function () {
    $fake = Gaze::fake();

    $chained = $fake->cleanUsing(fn (string $text, ?float $threshold): GazeSession => new GazeSession(
        cleanText: "fluent:{$text}",
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 0,
    ));

    expect($chained)->toBe($fake)
        ->and(Gaze::clean('Hello Alice')->cleanText)->toBe('fluent:Hello Alice');
});

it('configures the restore handler fluently', function () {
    Gaze::fake()->restoreUsing(fn (GazeSession $session, string $text): string => "restored:{$text}");

    $session = Gaze::clean('Hello Alice');

    expect(Gaze::restore($session, $session->cleanText))->toBe("restored:{$session->cleanText}");
});

it('configures the mask handler fluently without re-entering clean', function () {
    $fake = Gaze::fake()->maskUsing(fn (string $text): string => 'MASKED');

    expect(Gaze::mask('Hello Alice'))->toBe('MASKED')
        ->and($fake->maskCalls())->toHaveCount(1)
        ->and($fake->cleanCalls())->toBeEmpty();
});

it('passes the replace callable through to a fluent mask handler', function () {
    Gaze::fake()->maskUsing(function (string $text, ?callable $replace): string {
        return $replace !== null ? 'with-replace' : 'without-replace';
    });

    expect(Gaze::mask('Hello Alice', fn ($entry) => 'x'))->toBe('with-replace')
        ->and(Gaze::mask('Hello Alice'))->toBe('without-replace');
});

it('supports chaining multiple fluent handlers off Gaze::fake()', function () {
    Gaze::fake()
        ->cleanUsing(fn (string $text): GazeSession => new GazeSession(
            cleanText: 'chained-clean',
            ciphertext: EncryptedBlob::wrap('blob'),
            detections: 0,
        ))
        ->restoreUsing(fn (GazeSession $session, string $text): string => 'chained-restore');

    $session = Gaze::clean('Hello Alice');

    expect($session->cleanText)->toBe('chained-clean')
        ->and(Gaze::restore($session, $session->cleanText))->toBe('chained-restore');
});

it('throws the configured exception from clean after recording the call', function () {
    Gaze::fake()->failWith(fluentTestException());

    expect(fn () => Gaze::clean('Hello Alice'))
        ->toThrow(GazeException::class, 'binary exploded');

    // The attempt is still recorded, so failure-path tests can assert
    // the service was reached before it blew up.
    Gaze::assertCleaned('Hello Alice');
});

it('throws the configured exception from mask and restore', function () {
    $fake = Gaze::fake();
    $session = Gaze::clean('Hello Alice');

    $fake->failWith(fluentTestException());

    expect(fn () => Gaze::mask('Hello Alice'))
        ->toThrow(GazeException::class, 'binary exploded')
        ->and(fn () => Gaze::restore($session, $session->cleanText))
        ->toThrow(GazeException::class, 'binary exploded');

    Gaze::assertMasked('Hello Alice');
    Gaze::assertRestored($session->cleanText);
});

it('throws the configured exception from the daemon clean path too', function () {
    Gaze::fake()->failWith(fluentTestException());

    expect(fn () => Gaze::daemon()->session('s1')->clean('hi'))
        ->toThrow(GazeException::class, 'binary exploded');

    Gaze::assertDaemonCleaned('s1');
});

it('failWith takes precedence over a configured clean handler', function () {
    Gaze::fake()
        ->cleanUsing(fn (string $text): GazeSession => new GazeSession(
            cleanText: 'never returned',
            ciphertext: EncryptedBlob::wrap('blob'),
            detections: 0,
        ))
        ->failWith(fluentTestException());

    expect(fn () => Gaze::clean('Hello Alice'))->toThrow(GazeException::class);
});

it('configures the audit purge handler fluently', function () {
    Gaze::fake()->auditPurgeUsing(fn (string $before, bool $dryRun): AuditPurgeResult => new AuditPurgeResult(
        rawOutput: "fluent {$before}",
        count: 7,
    ));

    $result = Gaze::audit()->purge()->before('2026-01-01T00:00:00Z')->dryRun();

    expect($result->count)->toBe(7)
        ->and($result->rawOutput)->toBe('fluent 2026-01-01T00:00:00Z');
});

it('configures the audit export handler fluently', function () {
    Gaze::fake()->auditExportUsing(fn (?string $output, string $format): AuditExportResult => new AuditExportResult(
        format: $format,
        path: $output,
        rawOutput: '{"class":"email"}'."\n",
    ));

    expect(Gaze::audit()->query()->export()->rowCount())->toBe(1);
});

it('configures the daemon clean handler fluently', function () {
    Gaze::fake()->daemonCleanUsing(fn (string $sessionId, string $text): CleanResponse => new CleanResponse(
        sessionId: $sessionId,
        cleanText: "fluent:{$text}",
        manifest: [],
        tokens: [],
        raw: [],
    ));

    expect(Gaze::daemon()->session('s1')->clean('hi')->cleanText)->toBe('fluent:hi');
});

it('keeps the positional-closure constructor working alongside fluent overrides', function () {
    // Additive API: the constructor closure seeds the handler, a later
    // fluent call replaces it — no BC break either way.
    $fake = new FakeGaze(cleanHandler: fn (string $text): GazeSession => new GazeSession(
        cleanText: 'from-constructor',
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 0,
    ));

    expect($fake->clean('a')->cleanText)->toBe('from-constructor');

    $fake->cleanUsing(fn (string $text): GazeSession => new GazeSession(
        cleanText: 'from-fluent',
        ciphertext: EncryptedBlob::wrap('blob'),
        detections: 0,
    ));

    expect($fake->clean('a')->cleanText)->toBe('from-fluent');
});

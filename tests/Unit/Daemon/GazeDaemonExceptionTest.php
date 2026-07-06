<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeIntegrityException;
use CertaMesh\Gaze\Queue\Contracts\Retryable;

it('accepts an envelope without a stderrHash', function () {
    $exception = new GazeDaemonException(
        'pipeline failed',
        sessionId: 's1',
        raw: ['error' => 'Pipeline', 'detail' => 'pipeline failed'],
        daemonVariant: DaemonErrorVariant::Pipeline,
    );

    expect($exception)->toBeInstanceOf(GazeIntegrityException::class);
    expect($exception)->toBeInstanceOf(GazeException::class);
    expect($exception->getCode())->toBe(-1);
    expect($exception->exitCode)->toBe(-1);
    expect($exception->stderrHash)->toBeNull();
});

it('emits the envelope raw payload in toLogContext instead of stderr_sha256', function () {
    $raw = ['error' => 'Pipeline', 'detail' => 'pipeline failed', 'session_id' => 's1'];
    $exception = new GazeDaemonException(
        'pipeline failed',
        sessionId: 's1',
        raw: $raw,
        daemonVariant: DaemonErrorVariant::Pipeline,
    );

    $context = $exception->toLogContext();

    expect($context)->toHaveKey('daemon_variant', 'Pipeline');
    expect($context)->toHaveKey('session_id', 's1');
    expect($context)->toHaveKey('raw', $raw);
    expect($context)->not->toHaveKey('stderr_sha256');
});

it('is NOT instanceof Retryable — queue retry is adopter-owned', function () {
    $exception = new GazeDaemonException(
        'pipeline failed',
        sessionId: 's1',
        raw: [],
        daemonVariant: DaemonErrorVariant::Pipeline,
    );

    expect($exception)->not->toBeInstanceOf(Retryable::class);
});

it('exposes accessors for sessionId, raw, and daemonVariant', function () {
    $raw = ['x' => 1];
    $exception = new GazeDaemonException(
        'whoops',
        sessionId: 'abc',
        raw: $raw,
        daemonVariant: DaemonErrorVariant::Unknown,
    );

    expect($exception->sessionId())->toBe('abc');
    expect($exception->raw())->toBe($raw);
    expect($exception->daemonVariant())->toBe(DaemonErrorVariant::Unknown);
});

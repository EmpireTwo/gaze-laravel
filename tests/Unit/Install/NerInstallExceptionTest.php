<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Install\NerDiskSpaceException;
use CertaMesh\Gaze\Install\NerInstallException;
use CertaMesh\Gaze\Install\NerLockHeldException;
use CertaMesh\Gaze\Install\NerManifestInvalidException;
use CertaMesh\Gaze\Install\NerPolicyConflictException;
use CertaMesh\Gaze\Install\NerShaMismatchException;
use CertaMesh\Gaze\Install\NerTransportException;
use CertaMesh\Gaze\Install\NerVariantUnknownException;

dataset('ner install exceptions', [
    'disk space' => [NerDiskSpaceException::class],
    'lock held' => [NerLockHeldException::class],
    'manifest invalid' => [NerManifestInvalidException::class],
    'policy conflict' => [NerPolicyConflictException::class],
    'sha mismatch' => [NerShaMismatchException::class],
    'transport' => [NerTransportException::class],
    'variant unknown' => [NerVariantUnknownException::class],
]);

it('extends NerInstallException', function (string $class) {
    expect(is_subclass_of($class, NerInstallException::class))->toBeTrue();
})->with('ner install exceptions');

it('joins the GazeException tree', function (string $class) {
    expect(is_subclass_of($class, GazeException::class))->toBeTrue();
})->with('ner install exceptions');

it('remains a \RuntimeException instance', function (string $class) {
    expect(is_subclass_of($class, RuntimeException::class))->toBeTrue();
})->with('ner install exceptions');

it('NerInstallException extends GazeException', function () {
    expect(is_subclass_of(NerInstallException::class, GazeException::class))->toBeTrue();
});

it('catch (GazeException) catches NER install failures', function () {
    expect(fn () => throw new NerLockHeldException('/tmp/gaze-ner.lock'))
        ->toThrow(GazeException::class);
});

it('catch (NerInstallException) still works', function () {
    expect(fn () => throw new NerTransportException('boom'))
        ->toThrow(NerInstallException::class, 'boom');
});

it('NerInstallException carries an exit code', function () {
    $e = new NerVariantUnknownException('bad', ['int8']);

    expect($e->exitCode())->toBe(2);
});

it('mirrors exitCode() in the inherited GazeException properties', function () {
    $e = new NerLockHeldException('/tmp/gaze-ner.lock');

    expect($e->exitCode)->toBe($e->exitCode());
    expect($e->getCode())->toBe($e->exitCode());
    expect($e->variant)->toBeNull();
    expect($e->stderrHash)->toBe(hash('sha256', ''));
});

it('is never classified as a caller bug and logs a safe context', function () {
    $e = new NerDiskSpaceException(100, 50, '/tmp');

    expect($e->isCallerBug())->toBeFalse();
    expect($e->toLogContext())->toBe([
        'exit_code' => 1,
        'error_variant' => null,
        'stderr_sha256' => hash('sha256', ''),
    ]);
});

it('preserves the previous exception via the named argument', function () {
    $cause = new RuntimeException('io failed');
    $e = new NerTransportException('could not fetch', previous: $cause);

    expect($e->getPrevious())->toBe($cause);
});

it('NerShaMismatchException names the bad file', function () {
    $e = new NerShaMismatchException('model.onnx', 'expected', 'actual');

    expect($e->fileName)->toBe('model.onnx');
    expect($e->getMessage())->toContain('model.onnx');
});

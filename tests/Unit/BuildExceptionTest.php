<?php

declare(strict_types=1);

use CertaMesh\Gaze\Exceptions\GazeIoException;
use CertaMesh\Gaze\Exceptions\GazeSigPipeException;
use CertaMesh\Gaze\Exceptions\GazeUnknownTokenException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

it('maps stderr fixtures into typed exceptions', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode(['error' => 'UnknownToken', 'exit' => 3], JSON_THROW_ON_ERROR),
            exitCode: 3,
        ),
    ]);

    $this->makeGaze()->restore($this->bindAndReturnCleanSession('Hello Name_1', 'blob', 1), 'Hello Name_1');
})->throws(GazeUnknownTokenException::class);

it('treats empty-stderr sigpipe as a dedicated exception', function () {
    Log::spy();
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 141),
    ]);

    $this->makeGaze()->clean('Hello Alice');
})->throws(GazeSigPipeException::class);

it('keeps the empty-string hash for subprocess failures that emitted no stderr', function () {
    Log::spy();
    Process::fake([
        '*' => Process::result(output: '', errorOutput: '', exitCode: 141),
    ]);

    try {
        $this->makeGaze()->clean('Hello Alice');
    } catch (GazeSigPipeException $e) {
        // The subprocess ran and produced an (empty) stderr stream, so the
        // forensic hash stays hash('sha256', '') — null is reserved for
        // failures where no stderr stream ever existed.
        expect($e->stderrHash)->toBe(hash('sha256', ''))
            ->and($e->getMessage())->toContain('stderr_sha256='.hash('sha256', ''));

        return;
    }

    $this->fail('Expected GazeSigPipeException to be thrown.');
});

it('treats non-empty-stderr sigpipe as a parsed variant', function () {
    Process::fake([
        '*' => Process::result(
            output: '',
            errorOutput: json_encode(['error' => 'Io', 'exit' => 141], JSON_THROW_ON_ERROR),
            exitCode: 141,
        ),
    ]);

    $this->makeGaze()->clean('Hello Alice');
})->throws(GazeIoException::class);

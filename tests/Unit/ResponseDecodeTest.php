<?php

declare(strict_types=1);

use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Exceptions\GazeResponseDecodeException;
use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Facades\Process;

it('decodes the clean response into a session', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob-bytes',
            'stats' => ['detections' => 1],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->cleanText)->toBe('Hello Name_1')
        ->and($session->detections)->toBe(1)
        ->and($session->ciphertext)->toBeInstanceOf(EncryptedBlob::class)
        ->and($session->ciphertext->decryptedBlob())->toBe('blob-bytes');
});

it('decodes restore responses from the text key only', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'text' => 'Hello Alice',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob-bytes', 1);

    expect($this->makeGaze()->restore($session, 'Hello Name_1'))->toBe('Hello Alice');
});

it('maps malformed clean JSON output to a non-retryable decode exception', function () {
    Process::fake([
        '*' => Process::result(output: 'not-json{'),
    ]);

    $thrown = null;
    try {
        $this->makeGaze()->clean('Hello Alice');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    if (! $thrown instanceof GazeResponseDecodeException) {
        $this->fail('expected GazeResponseDecodeException');
    }

    expect($thrown)->toBeInstanceOf(GazeResponseDecodeException::class)
        ->and($thrown)->toBeInstanceOf(NonRetryable::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(JsonException::class);
});

it('reports a null stderr hash for synthetic errors with no subprocess stderr stream', function () {
    Process::fake([
        '*' => Process::result(output: 'not-json{'),
    ]);

    try {
        $this->makeGaze()->clean('Hello Alice');
    } catch (GazeResponseDecodeException $e) {
        expect($e->stderrHash)->toBeNull()
            ->and($e->getMessage())->toContain('stderr_sha256=none')
            ->and($e->toLogContext()['stderr_sha256'])->toBeNull();

        return;
    }

    $this->fail('expected GazeResponseDecodeException');
});

it('maps malformed restore JSON output to a non-retryable decode exception', function () {
    Process::fake([
        '*' => Process::result(output: 'oops'),
    ]);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob-bytes', 1);

    $thrown = null;
    try {
        $this->makeGaze()->restore($session, 'Hello Name_1');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(GazeResponseDecodeException::class)
        ->and($thrown)->toBeInstanceOf(NonRetryable::class);
});

it('maps corrupted session ciphertext to a non-retryable decode exception', function () {
    $brokenEncrypter = new class implements EncrypterContract, StringEncrypter
    {
        public function encrypt($value, $serialize = true): string
        {
            return 'ignored';
        }

        public function decrypt($payload, $unserialize = true): mixed
        {
            throw new DecryptException('bad payload');
        }

        public function encryptString($value): string
        {
            return 'ignored';
        }

        public function decryptString($payload): string
        {
            throw new DecryptException('bad payload');
        }

        public function getKey(): string
        {
            return str_repeat("\0", 32);
        }

        /** @return list<string> */
        public function getAllKeys(): array
        {
            return [];
        }

        /** @return list<string> */
        public function getPreviousKeys(): array
        {
            return [];
        }
    };
    $this->app->instance('gaze.encrypter', $brokenEncrypter);

    $session = $this->bindAndReturnCleanSession('Hello Name_1', 'blob-bytes', 1);

    $thrown = null;
    try {
        $this->makeGaze()->restore($session, 'Hello Name_1');
    } catch (Throwable $e) {
        $thrown = $e;
    }

    if (! $thrown instanceof GazeResponseDecodeException) {
        $this->fail('expected GazeResponseDecodeException');
    }

    expect($thrown)->toBeInstanceOf(GazeResponseDecodeException::class)
        ->and($thrown)->toBeInstanceOf(NonRetryable::class)
        ->and($thrown->getPrevious())->toBeInstanceOf(DecryptException::class);
});

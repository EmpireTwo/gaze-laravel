<?php

declare(strict_types=1);

use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\GazeSession;
use Illuminate\Encryption\Encrypter;

it('uses the host cipher for the dedicated encryption key path', function () {
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set(
        'gaze.blob_encryption_key',
        'base64:'.base64_encode(random_bytes(32)),
    );

    $encrypter = $this->app->make('gaze.encrypter');
    $reflection = new ReflectionClass($encrypter);
    $cipher = $reflection->getProperty('cipher')->getValue($encrypter);

    $blob = EncryptedBlob::wrap('secret-session-blob');

    expect($encrypter)->toBeInstanceOf(Encrypter::class)
        ->and($cipher)->toBe('AES-256-GCM')
        ->and($blob->decryptedBlob())->toBe('secret-session-blob');
});

it('round-trips a dedicated-key blob across separate config instances under AES-256-GCM', function () {
    $key = 'base64:'.base64_encode(random_bytes(32));
    $original = json_encode([
        'session_id' => 'abc123',
        'detections' => [
            ['label' => 'email', 'value' => 'alice@example.com'],
            ['label' => 'phone', 'value' => '+15551234567'],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set('gaze.blob_encryption_key', $key);

    $ciphertext = EncryptedBlob::wrap($original)->ciphertext();

    $this->refreshApplication();
    $this->app->forgetInstance('gaze.encrypter');
    $this->app['config']->set('app.cipher', 'AES-256-GCM');
    $this->app['config']->set('gaze.blob_encryption_key', $key);

    $restored = EncryptedBlob::fromCiphertext($ciphertext)->decryptedBlob();

    expect($restored)->toBe($original);
});

it('rehydrates a persisted session blob via fromCiphertext (supported cross-request path)', function () {
    // Request 1 — clean produced a session; the adopter persists the already-
    // encrypted ciphertext string (e.g. a DB column) plus the clean text.
    $sessionBlob = base64_encode(gl_jsonEncode(['text' => 'alice@example.com']));
    $session = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::wrap($sessionBlob),
        detections: 1,
    );

    $persistedCiphertext = $session->ciphertext->ciphertext();

    // The persisted string is opaque — never the plaintext blob.
    expect($persistedCiphertext)->not->toContain('alice@example.com')
        ->and($persistedCiphertext)->not->toBe($sessionBlob);

    // Request 2 — rehydrate the session from storage without re-encrypting.
    $rehydrated = new GazeSession(
        cleanText: 'Email_1',
        ciphertext: EncryptedBlob::fromCiphertext($persistedCiphertext),
        detections: 1,
    );

    expect($rehydrated->ciphertext->ciphertext())->toBe($persistedCiphertext)
        ->and($rehydrated->ciphertext->decryptedBlob())->toBe($sessionBlob);
});

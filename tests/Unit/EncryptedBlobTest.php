<?php

declare(strict_types=1);

use CertaMesh\Gaze\EncryptedBlob;
use Illuminate\Encryption\Encrypter;

it('wraps and decrypts the blob', function () {
    $blob = EncryptedBlob::wrap('secret-session-blob');

    expect($blob->ciphertext())->not->toBe('secret-session-blob')
        ->and($blob->decryptedBlob())->toBe('secret-session-blob');
});

it('round-trips from existing ciphertext', function () {
    $blob = EncryptedBlob::wrap('secret-session-blob');
    $rehydrated = EncryptedBlob::fromCiphertext($blob->ciphertext());

    expect($rehydrated->ciphertext())->toBe($blob->ciphertext())
        ->and($rehydrated->decryptedBlob())->toBe('secret-session-blob');
});

it('stays a minimal value object', function () {
    expect(get_class_methods(EncryptedBlob::class))->not->toContain('__toString')
        ->and(get_class_methods(EncryptedBlob::class))->not->toContain('toArray');
});

it('uses an injected encrypter for both wrap and decrypt without touching the container', function () {
    $dedicated = new Encrypter(random_bytes(32), 'AES-256-CBC');

    $blob = EncryptedBlob::wrap('secret-session-blob', $dedicated);

    expect($blob->decryptedBlob())->toBe('secret-session-blob')
        // The ciphertext must belong to the injected encrypter, not the
        // container-bound one.
        ->and($dedicated->decryptString($blob->ciphertext()))->toBe('secret-session-blob');
});

it('survives serialize/unserialize round-trips without embedding the encrypter', function () {
    $blob = EncryptedBlob::wrap('secret-session-blob');
    $serialized = serialize($blob);

    expect($serialized)->not->toContain('secret-session-blob')
        ->and($serialized)->not->toContain('Encrypter')
        ->and($serialized)->not->toContain('encrypter');

    $woken = unserialize($serialized);

    expect($woken)->toBeInstanceOf(EncryptedBlob::class)
        ->and($woken->ciphertext())->toBe($blob->ciphertext())
        ->and($woken->decryptedBlob())->toBe('secret-session-blob');
});

it('unserializes legacy default-serialized payloads (pre-injection package versions)', function () {
    // Shape produced before __serialize existed: default object serialization
    // of the single private string property.
    $ciphertext = EncryptedBlob::wrap('secret-session-blob')->ciphertext();
    $mangled = "\0".EncryptedBlob::class."\0ciphertext";
    $legacy = 'O:'.strlen(EncryptedBlob::class).':"'.EncryptedBlob::class.'":1:{'
        .'s:'.strlen($mangled).':"'.$mangled.'";'
        .'s:'.strlen($ciphertext).':"'.$ciphertext.'";}';

    $woken = unserialize($legacy);

    expect($woken)->toBeInstanceOf(EncryptedBlob::class)
        ->and($woken->decryptedBlob())->toBe('secret-session-blob');
});

<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;

final readonly class EncryptedBlob
{
    private function __construct(private string $ciphertext) {}

    public static function wrap(string $plaintextBlob): self
    {
        return new self(self::encrypter()->encryptString($plaintextBlob));
    }

    /**
     * Rehydrate a blob from previously persisted ciphertext.
     *
     * Supported public API: this is the rehydration point for adopters who
     * persist a session across requests (queue a job, park a conversation,
     * respond later). Store `$session->ciphertext->ciphertext()` wherever you
     * like — it is already encrypted — then rebuild the session when the LLM
     * reply arrives:
     *
     * ```php
     * // Request 1 — clean and persist
     * $session = Gaze::clean($input);
     * $model->update(['gaze_blob' => $session->ciphertext->ciphertext()]);
     *
     * // Request 2 — rehydrate and restore
     * $session = new GazeSession(
     *     cleanText: $model->clean_text,
     *     ciphertext: EncryptedBlob::fromCiphertext($model->gaze_blob),
     *     detections: $model->detections,
     * );
     * $reply = Gaze::restore($session, $llmReply);
     * ```
     *
     * No decryption happens here — the ciphertext is only unwrapped (with the
     * same `gaze.encrypter` key that produced it) when the session is restored.
     */
    public static function fromCiphertext(string $ciphertext): self
    {
        return new self($ciphertext);
    }

    public function ciphertext(): string
    {
        return $this->ciphertext;
    }

    public function decryptedBlob(): string
    {
        return self::encrypter()->decryptString($this->ciphertext);
    }

    /**
     * @return EncrypterContract&StringEncrypter
     */
    private static function encrypter(): EncrypterContract
    {
        /** @var EncrypterContract&StringEncrypter $encrypter */
        $encrypter = app()->bound('gaze.encrypter')
            ? app('gaze.encrypter')
            : app('encrypter');

        return $encrypter;
    }
}

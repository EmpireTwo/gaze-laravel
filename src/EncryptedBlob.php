<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;

final readonly class EncryptedBlob
{
    /**
     * The encrypter is a TRANSIENT collaborator, not state: it is injected
     * by the construction site (Gaze passes its own), never serialized (see
     * __serialize), and lazily re-resolved from the container only when a
     * blob wakes up from unserialize() or was rehydrated via
     * fromCiphertext() — the two paths where no injector exists.
     */
    private function __construct(
        private string $ciphertext,
        private (EncrypterContract&StringEncrypter)|null $encrypter = null,
    ) {}

    /**
     * Encrypt a plaintext session blob. Pass the encrypter that should own
     * this blob (the `gaze.encrypter` binding); omitting it falls back to
     * resolving that binding from the container, which keeps ad-hoc
     * construction (fakes, fixtures) working without wiring.
     */
    public static function wrap(
        string $plaintextBlob,
        (EncrypterContract&StringEncrypter)|null $encrypter = null,
    ): self {
        $encrypter ??= self::resolveEncrypter();

        return new self($encrypter->encryptString($plaintextBlob), $encrypter);
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
     * Pure — touches no container and performs no crypto. No encrypter is
     * bound on this path; decryptedBlob() lazily resolves the `gaze.encrypter`
     * binding (the same key that produced the ciphertext) when — and only
     * when — decryption is requested at restore time.
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
        return ($this->encrypter ?? self::resolveEncrypter())->decryptString($this->ciphertext);
    }

    /**
     * Only the ciphertext crosses serialization. The encrypter reference is
     * deliberately dropped — serializing it would embed the raw encryption
     * key in queue payloads / session stores, and a blob must survive a
     * round-trip into a process holding a different encrypter instance. On
     * wake, decryptedBlob() lazily re-resolves the bound encrypter.
     *
     * @return array{ciphertext: string}
     */
    public function __serialize(): array
    {
        return ['ciphertext' => $this->ciphertext];
    }

    /**
     * Accepts both the __serialize() shape and the legacy default-serialized
     * shape (mangled private-property key) so payloads produced by earlier
     * package versions — e.g. queued jobs in flight across a deploy — still
     * unserialize.
     *
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        $ciphertext = $data['ciphertext'] ?? $data["\0".self::class."\0ciphertext"] ?? null;

        if (! is_string($ciphertext)) {
            throw new \UnexpectedValueException('EncryptedBlob payload is missing its ciphertext.');
        }

        $this->ciphertext = $ciphertext;
        $this->encrypter = null;
    }

    /**
     * Container fallback for blobs with no injected encrypter (unserialized
     * or rehydrated via fromCiphertext). Prefers the dedicated
     * `gaze.encrypter` binding, then Laravel's default encrypter.
     *
     * @return EncrypterContract&StringEncrypter
     */
    private static function resolveEncrypter(): EncrypterContract
    {
        /** @var EncrypterContract&StringEncrypter $encrypter */
        $encrypter = app()->bound('gaze.encrypter')
            ? app('gaze.encrypter')
            : app('encrypter');

        return $encrypter;
    }
}

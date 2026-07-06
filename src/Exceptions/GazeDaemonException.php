<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Daemon\DaemonErrorVariant;

/**
 * Exception thrown by the long-lived `gaze daemon` JSONL adapter.
 *
 * Daemon errors are stdout JSON envelopes — they have no stderr payload to
 * hash like the one-shot `Gaze::clean()` failures. The parent ctor receives
 * `stderrHash = null` (no stderr stream ever existed); `toLogContext()` is
 * overridden to surface the envelope `raw` payload instead.
 *
 * This class is intentionally NOT `Retryable`: queue retry policy is the
 * adopter's responsibility, mirroring the one-shot semantics of the
 * underlying `gaze` binary. Transport / timeout subclasses inherit the
 * same posture.
 */
class GazeDaemonException extends GazeIntegrityException
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        string $message,
        public readonly ?string $sessionId,
        public readonly array $raw,
        public readonly DaemonErrorVariant $daemonVariant,
        ?\Throwable $previous = null,
    ) {
        // Daemon errors have no stderr — pass a null hash and rely on
        // toLogContext() for envelope-aware diagnostics. Exit code -1
        // mirrors the GazeResponseDecodeException precedent for line-
        // level errors with no upstream process exit.
        parent::__construct($message, -1, null, null, $previous);
    }

    public function daemonVariant(): DaemonErrorVariant
    {
        return $this->daemonVariant;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * Daemon-shaped log context override. Returns a structurally different
     * payload than the parent (`{exit_code, error_variant, stderr_sha256}`)
     * — daemon errors are stdout envelopes, not stderr hashes — so the
     * shape carries `{daemon_variant, session_id, raw}` instead. Adopters
     * that pipe `toLogContext()` into structured logs branch on
     * `instanceof GazeDaemonException` to read the daemon shape.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'daemon_variant' => $this->daemonVariant->value,
            'session_id' => $this->sessionId,
            'raw' => $this->raw,
        ];
    }

    public function logLevel(): string
    {
        return 'warning';
    }
}

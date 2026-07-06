<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

/**
 * Base exception for `gaze` subprocess failures.
 *
 * The inherited `getCode()` value is the upstream process exit code, not an
 * HTTP status or application-domain status. Use `$exitCode` for explicit reads.
 *
 * `$stderrHash` is the SHA-256 of the raw subprocess stderr (which may be the
 * hash of the empty string when the process ran but emitted nothing). It is
 * `null` when no subprocess stderr stream ever existed — pre-flight
 * validation, timeouts, stdout decode failures, and install-time errors.
 */
class GazeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $exitCode,
        public readonly ?string $stderrHash,
        public readonly ?Variant $variant = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $previous);
    }

    public function isCallerBug(): bool
    {
        return $this->variant?->exitBucket() === 1;
    }

    public function logLevel(): string
    {
        return 'warning';
    }

    /**
     * @return array{exit_code:int,error_variant:?string,stderr_sha256:?string}
     */
    public function toLogContext(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'error_variant' => $this->variant?->value,
            'stderr_sha256' => $this->stderrHash,
        ];
    }
}

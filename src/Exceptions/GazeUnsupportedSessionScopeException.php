<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Variant;

final class GazeUnsupportedSessionScopeException extends GazeIntegrityException implements NonRetryable
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly string $attemptedScope,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::UnsupportedSessionScope, $previous);
    }

    public function attemptedScope(): string
    {
        return $this->attemptedScope;
    }
}

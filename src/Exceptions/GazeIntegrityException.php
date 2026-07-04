<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

class GazeIntegrityException extends GazeException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        ?Variant $variant = Variant::UnknownToken,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }

    public function requiresFreshClean(): bool
    {
        return false;
    }

    public function logLevel(): string
    {
        return 'notice';
    }
}

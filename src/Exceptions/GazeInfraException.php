<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

abstract class GazeInfraException extends GazeException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        ?Variant $variant = Variant::Io,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }
}

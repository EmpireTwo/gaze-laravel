<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

class GazeBinaryMissingException extends GazeOpsConfigException
{
    public function __construct(
        string $message,
        int $exitCode = -1,
        ?string $stderrHash = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, null, $previous);
    }
}

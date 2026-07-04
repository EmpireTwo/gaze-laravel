<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Variant;

class GazeOpsConfigException extends GazeException implements NonRetryable
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        ?Variant $variant = Variant::PolicyConfig,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }

    public function logLevel(): string
    {
        return 'notice';
    }
}

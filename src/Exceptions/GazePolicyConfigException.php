<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

class GazePolicyConfigException extends GazeOpsConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        ?\Throwable $previous = null,
        Variant $variant = Variant::PolicyConfig,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, $variant, $previous);
    }
}

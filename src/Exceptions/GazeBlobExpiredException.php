<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Queue\Contracts\RequiresFreshClean;
use CertaMesh\Gaze\Variant;

class GazeBlobExpiredException extends GazeIntegrityException implements NonRetryable, RequiresFreshClean
{
    public function __construct(string $message, int $exitCode, ?string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::BlobExpired, $previous);
    }

    public function requiresFreshClean(): bool
    {
        return true;
    }
}

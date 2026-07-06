<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

final class GazeEmptyInputException extends GazeCallerBugException
{
    public function __construct(string $message, int $exitCode, ?string $stderrHash, ?\Throwable $previous = null)
    {
        parent::__construct($message, $exitCode, $stderrHash, Variant::EmptyInput, $previous);
    }
}

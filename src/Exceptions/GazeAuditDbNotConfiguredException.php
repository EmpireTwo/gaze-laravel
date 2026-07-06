<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

final class GazeAuditDbNotConfiguredException extends GazeCallerBugException
{
    public function __construct(string $message)
    {
        parent::__construct(
            message: $message,
            exitCode: -1,
            stderrHash: null,
        );
    }
}

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

    /**
     * Canonical fresh-clean signal: true when the session blob is permanently
     * unrecoverable and the only recovery is to re-run `Gaze::clean()` on the
     * original text before retrying `Gaze::restore()`. Overridden by
     * {@see GazeBlobExpiredException} and {@see GazeInvalidBlobVersionException}.
     * Supersedes the deprecated `Queue\Contracts\RequiresFreshClean` marker
     * interface.
     */
    public function requiresFreshClean(): bool
    {
        return false;
    }

    public function logLevel(): string
    {
        return 'notice';
    }
}

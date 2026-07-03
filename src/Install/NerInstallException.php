<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use CertaMesh\Gaze\Exceptions\GazeException;

/**
 * Base exception for NER model install failures.
 *
 * Part of the {@see GazeException} tree, so `catch (GazeException $e)` is the
 * single catch-all surface for every exception this package throws. Install
 * failures are produced without a `gaze` subprocess (including during
 * `composer install`, where no Laravel app exists), so the inherited
 * `$stderrHash` is the SHA-256 of the empty string and `$variant` is always
 * `null`. The inherited `$exitCode` property mirrors {@see exitCode()}.
 */
abstract class NerInstallException extends GazeException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct(
            $message,
            $this->exitCode(),
            hash('sha256', ''),
            null,
            $previous,
        );
    }

    /**
     * Suggested process exit code for this failure.
     *
     * Predates the {@see GazeException} `$exitCode` property; both expose the
     * same value.
     */
    abstract public function exitCode(): int;
}

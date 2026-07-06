<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition;
use CertaMesh\Gaze\Queue\RetryAction;
use CertaMesh\Gaze\Variant;

/**
 * The retry disposition of a safety-net failure depends on the upstream
 * `variant` sidecar, so this exception deliberately implements NONE of the
 * static marker interfaces (NonRetryable / Retryable / RetryableWithAlert).
 * Branch on {@see self::retryDisposition()} or `GazeRetryPolicy::classify()`.
 */
final class GazeSafetyNetFailureException extends GazeIntegrityException implements HasRetryDisposition
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly string $safetyNetVariant,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::SafetyNet, $previous);
    }

    public function safetyNetVariant(): string
    {
        return $this->safetyNetVariant;
    }

    public function isRetryable(): bool
    {
        return in_array($this->safetyNetVariant, ['Timeout', 'Other'], true);
    }

    public function isRetryableWithAlert(): bool
    {
        return $this->safetyNetVariant === 'SuspectedLeak';
    }

    public function isNonRetryable(): bool
    {
        return in_array($this->safetyNetVariant, ['InputTooLarge', 'Unsupported', 'WeightsMissing'], true);
    }

    /**
     * Unknown variants (upstream may add new ones) fail closed: anything not
     * explicitly retryable maps to RetryAction::Fail.
     */
    public function retryDisposition(): RetryAction
    {
        return match (true) {
            $this->isRetryableWithAlert() => RetryAction::ReleaseWithAlert,
            $this->isRetryable() => RetryAction::ReleaseWithBackoff,
            default => RetryAction::Fail,
        };
    }
}

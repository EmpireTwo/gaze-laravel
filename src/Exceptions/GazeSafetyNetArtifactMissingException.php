<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Queue\Contracts\NonRetryable;
use CertaMesh\Gaze\Variant;

/**
 * Pinned-artifact contract violation: a safety-net backend was requested but
 * its required artifact (e.g. `SHA256SUMS`, `model.onnx`, `tokenizer.json`,
 * `labels.json` for the Kiji DistilBERT backend) is missing on disk.
 *
 * Maps to upstream `CliError::SafetyNetArtifactMissing { backend, path }`
 * (exit 2). Axis-1 fail-closed: the binary never silently disables a backend
 * when its pinned artifact is absent. Retry-classified as
 * {@see NonRetryable} via the
 * `GazeOpsConfigException` ancestor — retrying without fixing the artifact
 * path cannot succeed.
 */
final class GazeSafetyNetArtifactMissingException extends GazePolicyConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly string $backend,
        private readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message,
            $exitCode,
            $stderrHash,
            $previous,
            Variant::SafetyNetArtifactMissing,
        );
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function path(): string
    {
        return $this->path;
    }
}

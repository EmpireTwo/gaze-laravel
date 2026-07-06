<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

/**
 * Thrown when the upstream `gaze` binary rejects a policy whose top-level
 * `schema_version` major.minor prefix does not match the binary's supported
 * range. Surfaces upstream wire shape:
 *   {"error":"PolicySchemaUnsupported","exit":2,"found":"...","supported":"..."}
 *
 * Distinct from {@see GazePolicyConfigException} so adopters crossing a
 * schema contract break see the version mismatch directly rather than a
 * generic policy-config rejection.
 */
final class GazePolicySchemaUnsupportedException extends GazeOpsConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly string $found,
        private readonly string $supported,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::PolicySchemaUnsupported, $previous);
    }

    /** Upstream-reported `schema_version` value found in the rejected policy. */
    public function found(): string
    {
        return $this->found;
    }

    /** Upstream-supported `major.minor` schema prefix at the running binary's version. */
    public function supported(): string
    {
        return $this->supported;
    }
}

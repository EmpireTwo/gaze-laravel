<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Exceptions;

use CertaMesh\Gaze\Variant;

final class GazePolicyConfigDetailException extends GazeOpsConfigException
{
    public function __construct(
        string $message,
        int $exitCode,
        ?string $stderrHash,
        private readonly ?string $detail = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $exitCode, $stderrHash, Variant::PolicyConfigDetail, $previous);
    }

    /**
     * Upstream-supplied `detail` sidecar string from the PolicyConfig envelope
     * (e.g. `"unknown bundled rulepack: garbage"`). Null when adapter could
     * not decode the field (defensive — upstream always emits it on this variant).
     */
    public function detail(): ?string
    {
        return $this->detail;
    }
}

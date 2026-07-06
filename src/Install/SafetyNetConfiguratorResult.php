<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Outcome of a {@see SafetyNetConfigurator} operation — see
 * {@see SafetyNetConfigStatus} for the possible `status` values.
 */
final class SafetyNetConfiguratorResult
{
    /**
     * @param  array<string, string>  $pairs
     */
    public function __construct(
        public readonly SafetyNetConfigStatus $status,
        public readonly array $pairs,
        public readonly string $envPath,
        public readonly ?string $backupPath,
    ) {}
}

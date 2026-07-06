<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Framework-agnostic input for {@see BinaryDownloader::install()}.
 *
 * `releaseBase` MUST already be gated by the caller for its trust context. The
 * opt-in Composer script hook resolves it through
 * {@see BinaryInstaller::resolveReleaseBase()} (which pins the canonical base in
 * production); the artisan path passes null so the downloader falls back to the
 * canonical hard-pin and exposes no override.
 */
final class BinaryDownloadOptions
{
    public function __construct(
        public readonly string $binDir,
        public readonly ?string $version = null,
        public readonly ?string $releaseBase = null,
        public readonly ?string $githubToken = null,
        public readonly bool $force = false,
        public readonly bool $skip = false,
    ) {}
}

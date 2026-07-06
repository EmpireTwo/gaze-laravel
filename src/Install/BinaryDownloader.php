<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Symfony\Component\Process\Process;

/**
 * Framework-agnostic gaze binary download/verify pipeline.
 *
 * Depends on neither Composer nor Laravel: progress/diagnostics flow through an
 * injected emitter `Closure(string $level, string $message): void` where
 * `$level ∈ {info, comment, warning, error}`. The caller maps each level onto
 * its own IO surface and channel:
 *   - info, comment  → stdout
 *   - warning, error → stderr
 *
 * This single class owns the checksum, redirect, and cross-host auth-strip
 * logic so there is exactly one security-critical download path shared by the
 * opt-in Composer script hook ({@see BinaryInstaller::postInstall()}) and the
 * `gaze:install:binary` artisan command. The `https://`-per-hop recheck and
 * the cross-host Authorization strip were moved here verbatim.
 *
 * Not `final`: the artisan commands inject this via the container and tests
 * substitute a subclass stub, so the download pipeline can be exercised without
 * the network.
 */
class BinaryDownloader
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = '0.12.0';

    /** Canonical, hard-pinned release base. */
    public const RELEASE_BASE = 'https://github.com/CertaMesh/gaze/releases/download';

    private const MAX_REDIRECTS = 5;

    /**
     * Run the download pipeline. Never throws — every failure mode resolves to
     * a {@see BinaryDownloadResult} so the Composer plugin path stays
     * best-effort and the artisan path can map the status to an exit code.
     *
     * @param  \Closure(string $level, string $message): void|null  $emit
     */
    public function install(BinaryDownloadOptions $opts, ?\Closure $emit = null): BinaryDownloadResult
    {
        $emit ??= static function (string $level, string $message): void {};

        $version = is_string($opts->version) && $opts->version !== '' ? $opts->version : self::PINNED_VERSION;

        if ($opts->skip) {
            $emit('comment', 'gaze-laravel: skipping binary download (GAZE_SKIP_BINARY_DOWNLOAD=1)');

            return new BinaryDownloadResult(BinaryDownloadStatus::Skipped, null, $version, 'skipped');
        }

        $releaseBase = is_string($opts->releaseBase) && $opts->releaseBase !== '' ? $opts->releaseBase : self::RELEASE_BASE;
        if (! str_starts_with($releaseBase, 'https://')) {
            $emit('error', 'gaze-laravel: refusing non-HTTPS release base');

            return new BinaryDownloadResult(BinaryDownloadStatus::Failed, null, $version, 'non-HTTPS release base');
        }

        $target = self::detectTarget();
        if ($target === null) {
            $emit('error', 'gaze-laravel: unsupported platform, please install gaze manually and set GAZE_BINARY');

            return new BinaryDownloadResult(BinaryDownloadStatus::UnsupportedPlatform, null, $version, 'unsupported platform');
        }

        if ($target === 'x86_64-apple-darwin') {
            $emit('error', 'gaze-laravel: pre-built macOS binaries are arm64-only on Intel; clone https://github.com/CertaMesh/gaze and run `cargo install --path crates/gaze-cli`, then set GAZE_BINARY.');

            return new BinaryDownloadResult(BinaryDownloadStatus::UnsupportedPlatform, null, $version, 'intel macOS has no pre-built binary');
        }

        $binPath = rtrim($opts->binDir, '/\\').DIRECTORY_SEPARATOR.'gaze';
        if (! $opts->force && self::alreadyInstalled($binPath, $version)) {
            $emit('info', "gaze-laravel: gaze v{$version} already installed");

            return new BinaryDownloadResult(BinaryDownloadStatus::AlreadySatisfied, $binPath, $version, 'already installed');
        }

        $tag = "v{$version}";
        $asset = "gaze-{$target}";
        $assetUrl = "{$releaseBase}/{$tag}/{$asset}";
        $sumsUrl = "{$releaseBase}/{$tag}/{$asset}.sha256";

        $tmpDir = sys_get_temp_dir();
        $assetPath = $tmpDir.DIRECTORY_SEPARATOR.$asset;
        $sumsPath = $tmpDir.DIRECTORY_SEPARATOR."{$asset}.sha256";

        $token = is_string($opts->githubToken) && $opts->githubToken !== '' ? $opts->githubToken : null;
        $githubRepo = self::deriveGithubRepo($releaseBase);

        try {
            if ($token !== null && $githubRepo !== null) {
                [$assetId, $sumsAssetId] = self::resolveReleaseAssetIds($githubRepo, $tag, $asset, $token);
                self::downloadGithubAsset($githubRepo, $assetId, $assetPath, $token);
                self::downloadGithubAsset($githubRepo, $sumsAssetId, $sumsPath, $token);
            } else {
                if ($token !== null && $githubRepo === null) {
                    $emit('warning', 'gaze-laravel: GAZE_GITHUB_TOKEN set but GAZE_RELEASE_BASE is not a github.com release URL — token ignored');
                }
                self::download($assetUrl, $assetPath);
                self::download($sumsUrl, $sumsPath);
            }
            self::verifyChecksum($assetPath, $sumsPath, $asset);
            self::installBinary($assetPath, $binPath);
            @chmod($binPath, 0755);
            $emit('info', "gaze-laravel: installed gaze v{$version} → {$binPath}");
            $emit('comment', 'gaze-laravel: gaze proxy is opt-in. To use `php artisan gaze:proxy:*`, rebuild upstream with: cargo install gaze-cli --features proxy');

            return new BinaryDownloadResult(BinaryDownloadStatus::Installed, $binPath, $version, 'installed');
        } catch (\Throwable $e) {
            // Scrub the token if it ever ended up in an exception message.
            $message = $token !== null ? str_replace($token, '[redacted]', $e->getMessage()) : $e->getMessage();
            $emit('error', "gaze-laravel: binary install failed — {$message}");
            @unlink($binPath); // never leave partial artifact

            return new BinaryDownloadResult(BinaryDownloadStatus::Failed, null, $version, $message);
        } finally {
            @unlink($assetPath);
            @unlink($sumsPath);
        }
    }

    public static function detectTarget(): ?string
    {
        $os = strtolower(PHP_OS_FAMILY);
        $arch = strtolower(php_uname('m'));

        return match (true) {
            $os === 'darwin' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-apple-darwin',
            $os === 'darwin' && $arch === 'x86_64' => 'x86_64-apple-darwin',
            $os === 'linux' && $arch === 'x86_64' => 'x86_64-linux-gnu',
            $os === 'linux' && in_array($arch, ['arm64', 'aarch64'], true) => 'aarch64-unknown-linux-gnu',
            default => null,
        };
    }

    /**
     * True when the executable at $binPath reports exactly $version.
     *
     * The semver token is extracted from the `--version` output and compared
     * with `===` — a naive substring check would let `0.11.1` satisfy a pin of
     * `0.11.10` (and vice versa), silently skipping a required download.
     */
    public static function alreadyInstalled(string $binPath, string $version): bool
    {
        if (! is_executable($binPath)) {
            return false;
        }

        $process = new Process([$binPath, '--version']);
        $process->setTimeout(5);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        if (! $process->isSuccessful()) {
            return false;
        }

        // Extract the version token (e.g. `gaze 0.12.0` → `0.12.0`), then
        // compare exactly. Pre-release/build suffixes are part of the token.
        $reported = self::parseVersion($process->getOutput());

        return $reported !== null && $reported === $version;
    }

    /**
     * Extract the semver token from `gaze --version` output (e.g.
     * `gaze 0.12.0` → `0.12.0`), or null when none is present. Pre-release and
     * build suffixes are part of the token, so a `===` on the result is exact.
     *
     * Single source of truth shared with {@see alreadyInstalled} (install-skip
     * decision) and the `gaze:doctor` pin-mismatch probe, so both agree on what
     * "the installed version" is — a naive substring check would let `0.11.1`
     * satisfy a pin of `0.11.10`.
     */
    public static function parseVersion(string $output): ?string
    {
        if (preg_match('/\b(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)\b/', $output, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    public static function verifyChecksum(string $tarPath, string $sumsPath, string $asset): void
    {
        $sums = @file_get_contents($sumsPath);
        if ($sums === false) {
            throw new \RuntimeException('SHA256SUMS unreadable');
        }

        $expected = null;
        foreach (preg_split('/\r\n|\n/', $sums) ?: [] as $line) {
            if (preg_match('/^([a-f0-9]{64})\s+\*?'.preg_quote($asset, '/').'$/i', trim($line), $m)) {
                $expected = strtolower($m[1]);
                break;
            }
        }
        if ($expected === null) {
            throw new \RuntimeException("no checksum entry for {$asset}");
        }

        $actual = hash_file('sha256', $tarPath);
        if ($actual === false) {
            throw new \RuntimeException("could not hash {$tarPath}");
        }
        if (! hash_equals($expected, $actual)) {
            throw new \RuntimeException("sha256 mismatch for {$asset}");
        }
    }

    /**
     * Upstream release assets are raw binaries named `gaze-{target}` (no
     * archive wrapper — verified against the pinned release's asset list), so
     * installing is a plain copy of the checksum-verified download.
     */
    public static function installBinary(string $assetPath, string $binPath): void
    {
        if (@copy($assetPath, $binPath) === false) {
            throw new \RuntimeException("could not write {$binPath}");
        }
    }

    /**
     * Download the URL to destPath. Redirects are followed manually so every
     * hop is re-checked for `https://`; PHP's native `follow_location` does
     * not enforce this and would silently downgrade to plain HTTP. The
     * Authorization header is dropped on cross-host redirects (e.g. when
     * api.github.com hands off to an S3 signed URL — adding our Bearer to
     * that pre-signed URL would either leak the token or 400 the request).
     *
     * @param  list<string>  $headers
     */
    private static function download(string $url, string $destPath, array $headers = []): void
    {
        $bytes = self::fetch($url, $headers);
        if (file_put_contents($destPath, $bytes) === false) {
            throw new \RuntimeException("could not write {$destPath}");
        }
    }

    /**
     * @param  list<string>  $headers
     */
    private static function fetch(string $url, array $headers = []): string
    {
        $originalHost = self::hostOf($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! str_starts_with($url, 'https://')) {
                throw new \RuntimeException('refusing non-HTTPS url');
            }

            $currentHost = self::hostOf($url);
            $effectiveHeaders = self::stripAuthOnCrossHost($headers, $originalHost, $currentHost);

            $opts = [
                'method' => 'GET',
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 60,
            ];
            if ($effectiveHeaders !== []) {
                $opts['header'] = implode("\r\n", $effectiveHeaders);
            }

            $ctx = stream_context_create([
                'http' => $opts,
                'https' => $opts,
            ]);

            $bytes = @file_get_contents($url, false, $ctx);
            if ($bytes === false) {
                throw new \RuntimeException("download failed: {$url}");
            }

            /** @var list<string> $responseHeaders */
            $responseHeaders = $http_response_header;

            [$status, $location] = self::parseStatusAndLocation($responseHeaders);

            if ($status >= 200 && $status < 300) {
                return $bytes;
            }

            if ($status >= 300 && $status < 400 && $location !== null) {
                $url = self::resolveLocation($url, $location);

                continue;
            }

            throw new \RuntimeException("download failed with HTTP {$status}");
        }

        throw new \RuntimeException('too many redirects');
    }

    /**
     * Derive `<owner>/<repo>` from a GitHub release-download base URL, or
     * return null when the base is not a github.com release URL.
     */
    public static function deriveGithubRepo(string $releaseBase): ?string
    {
        if (preg_match('~^https://github\.com/([^/]+)/([^/]+)/releases/download~', $releaseBase, $m)) {
            return $m[1].'/'.$m[2];
        }

        return null;
    }

    /**
     * Build the request headers for a GitHub API or asset call. The token is
     * never logged; the Authorization line is only present when a token is
     * supplied.
     *
     * @return list<string>
     */
    public static function buildRequestHeaders(?string $token, string $accept): array
    {
        $headers = [
            'User-Agent: gaze-laravel/'.self::PINNED_VERSION,
            'Accept: '.$accept,
        ];
        if ($token !== null && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
            $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
        }

        return $headers;
    }

    /**
     * Resolve the asset id pair (binary + .sha256) for a given release tag,
     * via a single `releases/tags/{tag}` API call.
     *
     * @return array{0: int, 1: int}
     */
    private static function resolveReleaseAssetIds(string $repo, string $tag, string $asset, string $token): array
    {
        $url = "https://api.github.com/repos/{$repo}/releases/tags/{$tag}";
        $body = self::fetch($url, self::buildRequestHeaders($token, 'application/vnd.github+json'));

        return self::extractAssetIds($body, $asset, $tag);
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function extractAssetIds(string $jsonBody, string $asset, string $tag): array
    {
        $payload = json_decode($jsonBody, true);
        if (! is_array($payload) || ! isset($payload['assets']) || ! is_array($payload['assets'])) {
            throw new \RuntimeException("github release {$tag}: invalid JSON or no assets[]");
        }

        $byName = [];
        foreach ($payload['assets'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            $id = $entry['id'] ?? null;
            if (is_string($name) && is_int($id)) {
                $byName[$name] = $id;
            }
        }

        $sumsName = $asset.'.sha256';
        if (! isset($byName[$asset])) {
            throw new \RuntimeException("github release {$tag}: asset {$asset} not found");
        }
        if (! isset($byName[$sumsName])) {
            throw new \RuntimeException("github release {$tag}: asset {$sumsName} not found");
        }

        return [$byName[$asset], $byName[$sumsName]];
    }

    private static function downloadGithubAsset(string $repo, int $assetId, string $destPath, string $token): void
    {
        $url = "https://api.github.com/repos/{$repo}/releases/assets/{$assetId}";
        self::download($url, $destPath, self::buildRequestHeaders($token, 'application/octet-stream'));
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    /**
     * Drop the Authorization header when following a redirect to a different
     * host. This is the same rule curl applies with `--location` (without
     * `--location-trusted`) and what `gh` follows when fetching private
     * release assets — the redirect target is a presigned S3 URL and our
     * Bearer token has no business going there.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    public static function stripAuthOnCrossHost(array $headers, ?string $originalHost, ?string $currentHost): array
    {
        if ($originalHost !== null && $currentHost !== null && $originalHost === $currentHost) {
            return $headers;
        }

        return array_values(array_filter(
            $headers,
            static fn (string $h): bool => stripos($h, 'Authorization:') !== 0,
        ));
    }

    /**
     * @param  list<string>  $headers
     * @return array{0: int, 1: ?string}
     */
    public static function parseStatusAndLocation(array $headers): array
    {
        $status = 0;
        $location = null;
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+ (\d{3})~', $header, $m)) {
                $status = (int) $m[1];
                $location = null; // reset across multiple status lines
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, 9));
            }
        }

        return [$status, $location];
    }

    public static function resolveLocation(string $current, string $location): string
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('could not parse redirect origin URL');
        }
        $base = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $base.$location;
        }

        // Relative path — strip file from current, join.
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);

        return $base.$dir.$location;
    }
}

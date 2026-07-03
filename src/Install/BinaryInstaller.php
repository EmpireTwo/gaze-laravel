<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;

/**
 * Composer-context adapter over {@see BinaryDownloader}.
 *
 * Keeps its historical `install(Composer, $io)` / `postInstall(Event)` surface
 * (referenced by opt-in `composer.json` `post-update-cmd` / `post-install-cmd`
 * script entries — see UPGRADING.md) and resolves the release base in the
 * Composer trust context — `resolveReleaseBase()` pins the canonical base in
 * production so a `GAZE_RELEASE_BASE` override can never repoint a production
 * download.
 *
 * The download/verify pipeline itself lives in {@see BinaryDownloader}; the
 * static helpers below remain as thin delegating shims so existing call sites
 * (and the characterization tests) keep working unchanged.
 */
final class BinaryInstaller
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = BinaryDownloader::PINNED_VERSION;

    private const RELEASE_BASE = BinaryDownloader::RELEASE_BASE;

    /**
     * Composer script handler kept as a thin shim so an adopter's opt-in
     * `composer.json` `post-install-cmd` / `post-update-cmd` entry that
     * references this static keeps working. Nothing runs on `composer install`
     * by default now that the plugin is gone — wiring this is explicit (see
     * UPGRADING.md); `php artisan gaze:install` remains the canonical path.
     */
    public static function postInstall(Event $event): void
    {
        self::install($event->getComposer(), $event->getIO());
    }

    public static function install(Composer $composer, IOInterface $io): void
    {
        // Resolve + gate the release base in the Composer trust context BEFORE
        // handing it to the framework-agnostic downloader. resolveReleaseBase()
        // pins the canonical base in production (ignoring any override) and
        // emits the non-production override warning on stderr.
        $releaseBase = self::resolveReleaseBase($io);

        $version = getenv('GAZE_VERSION');
        $token = getenv('GAZE_GITHUB_TOKEN');

        (new BinaryDownloader)->install(
            new BinaryDownloadOptions(
                binDir: (string) $composer->getConfig()->get('bin-dir'),
                version: is_string($version) && $version !== '' ? $version : null,
                releaseBase: $releaseBase,
                githubToken: is_string($token) && $token !== '' ? $token : null,
                skip: getenv('GAZE_SKIP_BINARY_DOWNLOAD') === '1',
            ),
            self::composerEmitter($io),
        );
    }

    /**
     * Map the downloader's semantic level onto Composer's IO channel + markup,
     * preserving the exact stdout-vs-stderr routing each message had before the
     * pipeline was extracted:
     *   info, comment  → write (stdout)
     *   warning, error → writeError (stderr)
     *
     * @return \Closure(string, string): void
     */
    private static function composerEmitter(IOInterface $io): \Closure
    {
        return static function (string $level, string $message) use ($io): void {
            match ($level) {
                'error' => $io->writeError('<error>'.$message.'</error>'),
                'warning' => $io->writeError('<comment>'.$message.'</comment>'),
                'comment' => $io->write('<comment>'.$message.'</comment>'),
                default => $io->write('<info>'.$message.'</info>'),
            };
        };
    }

    /**
     * Resolve the release base in the Composer trust context. In production the
     * canonical base is always returned (any `GAZE_RELEASE_BASE` override is
     * ignored — supply-chain hard-pin); outside production an override is
     * honoured but logged.
     *
     * @internal exposed for tests
     */
    public static function resolveReleaseBase(IOInterface $io): string
    {
        $releaseBase = getenv('GAZE_RELEASE_BASE');
        if (! is_string($releaseBase) || $releaseBase === '') {
            return self::RELEASE_BASE;
        }

        if (self::isProductionEnvironment()) {
            return self::RELEASE_BASE;
        }

        $io->writeError('<comment>gaze-laravel: using non-canonical GAZE_RELEASE_BASE override outside production</comment>');

        return $releaseBase;
    }

    /** @internal exposed for tests */
    public static function isProductionEnvironment(): bool
    {
        $appEnv = getenv('APP_ENV');
        if (! is_string($appEnv) || trim($appEnv) === '') {
            return true;
        }

        return in_array(strtolower(trim($appEnv)), ['production', 'prod'], true);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::detectTarget()}
     */
    public static function detectTarget(): ?string
    {
        return BinaryDownloader::detectTarget();
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::alreadyInstalled()}
     */
    public static function alreadyInstalled(string $binPath, string $version): bool
    {
        return BinaryDownloader::alreadyInstalled($binPath, $version);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::verifyChecksum()}
     */
    public static function verifyChecksum(string $tarPath, string $sumsPath, string $asset): void
    {
        BinaryDownloader::verifyChecksum($tarPath, $sumsPath, $asset);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::extract()}
     */
    public static function extract(string $tarPath, string $binDir): void
    {
        BinaryDownloader::extract($tarPath, $binDir);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::installBinary()}
     */
    public static function installBinary(string $assetPath, string $binPath): void
    {
        BinaryDownloader::installBinary($assetPath, $binPath);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::deriveGithubRepo()}
     */
    public static function deriveGithubRepo(string $releaseBase): ?string
    {
        return BinaryDownloader::deriveGithubRepo($releaseBase);
    }

    /**
     * @return list<string>
     *
     * @internal delegating shim — see {@see BinaryDownloader::buildRequestHeaders()}
     */
    public static function buildRequestHeaders(?string $token, string $accept): array
    {
        return BinaryDownloader::buildRequestHeaders($token, $accept);
    }

    /**
     * @return array{0: int, 1: int}
     *
     * @internal delegating shim — see {@see BinaryDownloader::extractAssetIds()}
     */
    public static function extractAssetIds(string $jsonBody, string $asset, string $tag): array
    {
        return BinaryDownloader::extractAssetIds($jsonBody, $asset, $tag);
    }

    /**
     * @param  list<string>  $headers
     * @return array{0: int, 1: ?string}
     *
     * @internal delegating shim — see {@see BinaryDownloader::parseStatusAndLocation()}
     */
    public static function parseStatusAndLocation(array $headers): array
    {
        return BinaryDownloader::parseStatusAndLocation($headers);
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     *
     * @internal delegating shim — see {@see BinaryDownloader::stripAuthOnCrossHost()}
     */
    public static function stripAuthOnCrossHost(array $headers, ?string $originalHost, ?string $currentHost): array
    {
        return BinaryDownloader::stripAuthOnCrossHost($headers, $originalHost, $currentHost);
    }

    /**
     * @internal delegating shim — see {@see BinaryDownloader::resolveLocation()}
     */
    public static function resolveLocation(string $current, string $location): string
    {
        return BinaryDownloader::resolveLocation($current, $location);
    }
}

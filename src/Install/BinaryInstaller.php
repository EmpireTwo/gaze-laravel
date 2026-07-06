<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Script\Event;

/**
 * Composer-context adapter over {@see BinaryDownloader}.
 *
 * Its only entry point is {@see postInstall()}, the opt-in
 * `post-install-cmd` / `post-update-cmd` script hook documented in README.md
 * and UPGRADING.md. It resolves the release base in the Composer trust
 * context — {@see resolveReleaseBase()} pins the canonical base in production
 * so a `GAZE_RELEASE_BASE` override can never repoint a production download —
 * then hands off to the framework-agnostic {@see BinaryDownloader} pipeline.
 */
final class BinaryInstaller
{
    /** Pinned per gaze-laravel release. Bumped intentionally. */
    public const PINNED_VERSION = BinaryDownloader::PINNED_VERSION;

    private const RELEASE_BASE = BinaryDownloader::RELEASE_BASE;

    /**
     * Composer script handler — the opt-in `post-install-cmd` /
     * `post-update-cmd` entry point (see UPGRADING.md). Nothing runs on
     * `composer install` by default now that the plugin is gone — wiring this
     * is explicit; `php artisan gaze:install` remains the canonical path.
     */
    public static function postInstall(Event $event): void
    {
        self::install($event->getComposer(), $event->getIO());
    }

    private static function install(Composer $composer, IOInterface $io): void
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

    private static function isProductionEnvironment(): bool
    {
        $appEnv = getenv('APP_ENV');
        if (! is_string($appEnv) || trim($appEnv) === '') {
            return true;
        }

        return in_array(strtolower(trim($appEnv)), ['production', 'prod'], true);
    }
}

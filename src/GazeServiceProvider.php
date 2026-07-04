<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\Console\AuditPurgeCommand;
use CertaMesh\Gaze\Console\BenchCommand;
use CertaMesh\Gaze\Console\CanaryCommand;
use CertaMesh\Gaze\Console\CheckCommand;
use CertaMesh\Gaze\Console\Daemon\DaemonServeCommand;
use CertaMesh\Gaze\Console\Daemon\DaemonStatusCommand;
use CertaMesh\Gaze\Console\DoctorCommand;
use CertaMesh\Gaze\Console\Install\InstallBinaryCommand;
use CertaMesh\Gaze\Console\Install\InstallCommand;
use CertaMesh\Gaze\Console\Install\InstallSafetyNetCommand;
use CertaMesh\Gaze\Console\InstallNerCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyLogsCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyRestartCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyServeCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyStartCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyStatusCommand;
use CertaMesh\Gaze\Console\Proxy\ProxyStopCommand;
use CertaMesh\Gaze\Contracts\AuditService as AuditServiceContract;
use CertaMesh\Gaze\Contracts\DaemonManager as DaemonManagerContract;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;
use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Exceptions\GazeDaemonFeatureUnsupportedException;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryInstaller;
use CertaMesh\Gaze\Install\LaravelNerFetcher;
use CertaMesh\Gaze\Install\NerFetcher;
use CertaMesh\Gaze\Install\NerInstaller;
use CertaMesh\Gaze\Install\NerManifest;
use CertaMesh\Gaze\Install\PolicyTomlPatcher;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Deliberately NOT a DeferrableProvider: every binding here is a cheap
 * closure (nothing is constructed until first resolution anyway), and
 * deferral caused the known gotcha where `config('gaze.*')` read as null
 * during HTTP requests that never resolved a gaze service — the package
 * config was only merged once a deferred binding was touched.
 */
class GazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gaze.php', 'gaze');
        $this->normalizeSafetyNetConfig();

        $this->app->singleton(BinaryDownloader::class);

        $this->app->singleton(BinaryResolver::class, function (Application $app): BinaryResolver {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $explicit = $config->get('gaze.binary');

            return new BinaryResolver(
                explicitPath: is_string($explicit) && $explicit !== '' ? $explicit : null,
                vendorBinPath: $app->basePath('vendor/bin/gaze'),
            );
        });

        // Canonical binding lives on the contract; the concrete FQCN is an
        // alias of it so both `make(Contracts\Gaze::class)` and the historical
        // `make(Gaze::class)` resolve the same singleton — and a facade-level
        // swap (`Gaze::fake()`) replaces both.
        $this->app->singleton(GazeContract::class, function (Application $app): Gaze {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            /** @var array<string, mixed> $gazeConfig */
            $gazeConfig = (array) $config->get('gaze', []);
            $policyPath = $gazeConfig['policy_path'] ?? null;

            /** @var \Illuminate\Contracts\Encryption\Encrypter&StringEncrypter $encrypter */
            $encrypter = $app->make('gaze.encrypter');

            return new Gaze(
                resolver: $app->make(BinaryResolver::class),
                process: $app->make(ProcessFactory::class),
                container: $app,
                policyPath: is_string($policyPath) && $policyPath !== ''
                    ? $policyPath
                    : $app->basePath('policy.toml'),
                options: GazeOptions::fromConfig($gazeConfig),
                encrypter: $encrypter,
            );
        });

        $this->app->alias(GazeContract::class, Gaze::class);

        $this->app->scoped(DaemonClientContract::class, function (Application $app): DaemonClientContract {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            $policyPath = $config->get('gaze.daemon.policy_path');
            if (! is_string($policyPath) || $policyPath === '') {
                throw new GazeDaemonFeatureUnsupportedException(
                    'gaze.daemon.policy_path is not configured. Set GAZE_DAEMON_POLICY_PATH (or config) to enable daemon mode.'
                );
            }

            $binaryPath = $config->get('gaze.daemon.binary_path');
            if (! is_string($binaryPath) || $binaryPath === '') {
                $binaryPath = $app->make(BinaryResolver::class)->resolve();
            }

            $flags = [];
            $flags[] = '--policy='.$policyPath;
            $idle = $config->get('gaze.daemon.idle_timeout_s');
            if (is_numeric($idle)) {
                $flags[] = '--idle-timeout='.((int) $idle);
            }
            $auditDbPath = $config->get('gaze.daemon.audit_db_path');
            if (is_string($auditDbPath) && $auditDbPath !== '') {
                $flags[] = '--audit-db='.$auditDbPath;
            }

            $requestTimeoutMs = $config->get('gaze.daemon.request_timeout_ms', 5000);
            $requestTimeoutMs = is_numeric($requestTimeoutMs) ? (int) $requestTimeoutMs : 5000;

            $stderrPath = $config->get('gaze.daemon.stderr_path');
            $stderrPath = is_string($stderrPath) && $stderrPath !== '' ? $stderrPath : null;

            return new DaemonClient(
                binary: $binaryPath,
                flags: $flags,
                requestTimeoutMs: $requestTimeoutMs,
                stderrPath: $stderrPath,
            );
        });

        $this->app->scoped(DaemonManagerContract::class, function (Application $app): DaemonManager {
            return new DaemonManager($app->make(DaemonClientContract::class));
        });

        $this->app->alias(DaemonManagerContract::class, DaemonManager::class);

        $this->app->singleton(AuditServiceContract::class, function (Application $app): AuditService {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $rawAuditDbPath = $config->get('gaze.audit_db_path');

            return new AuditService(
                gaze: $app->make(Gaze::class),
                resolver: $app->make(BinaryResolver::class),
                auditDbPath: is_string($rawAuditDbPath) && $rawAuditDbPath !== '' ? $rawAuditDbPath : null,
            );
        });

        $this->app->alias(AuditServiceContract::class, AuditService::class);

        $this->app->singleton('gaze.http_client', function (): HttpClientInterface {
            return new RetryableHttpClient(HttpClient::create(), maxRetries: 2);
        });

        $this->app->singleton(LaravelNerFetcher::class, function (Application $app): LaravelNerFetcher {
            return new LaravelNerFetcher(
                client: $app->make('gaze.http_client'),
                resourcesDir: __DIR__.'/../resources/ner',
            );
        });

        $this->app->singleton(NerFetcher::class, LaravelNerFetcher::class);

        $this->app->singleton(NerManifest::class, function (Application $app): NerManifest {
            $version = BinaryInstaller::PINNED_VERSION;
            $url = "https://github.com/CertaMesh/gaze/releases/download/v{$version}/SHA256SUMS.ner";

            return NerManifest::fromUrl($url, $app->make('gaze.http_client'));
        });

        $this->app->singleton(PolicyTomlPatcher::class, function (Application $app): PolicyTomlPatcher {
            return new PolicyTomlPatcher(baseDir: $app->basePath());
        });

        $this->app->singleton(SafetyNetConfigurator::class, function (Application $app): SafetyNetConfigurator {
            return new SafetyNetConfigurator($app->basePath('.env'));
        });

        $this->app->singleton(NerInstaller::class, function (Application $app): NerInstaller {
            return new NerInstaller(
                fetcher: $app->make(NerFetcher::class),
                patcher: $app->make(PolicyTomlPatcher::class),
                manifest: $app->make(NerManifest::class),
            );
        });

        $this->app->singleton('gaze.encrypter', function (Application $app) {
            /** @var ConfigRepository $config */
            $config = $app->make('config');
            $raw = $config->get('gaze.blob_encryption_key');

            if ($raw === null || $raw === '') {
                return $app->make('encrypter');
            }

            if (! is_string($raw)) {
                throw new \RuntimeException('GAZE_ENCRYPTION_KEY must be a string.');
            }

            if (str_starts_with($raw, 'base64:')) {
                $raw = substr($raw, 7);
            }

            $decoded = base64_decode($raw, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new \RuntimeException(
                    'GAZE_ENCRYPTION_KEY must be base64-encoded 32 bytes.'
                );
            }

            $cipher = $config->get('app.cipher');
            if (! is_string($cipher) || $cipher === '') {
                $cipher = 'AES-256-CBC';
            }

            return new Encrypter($decoded, $cipher);
        });
    }

    /**
     * Bridge the nested `gaze.safety_net.*` group (v0.13+ config shape) to
     * the deprecated flat root keys so BOTH spellings observe the same
     * values at runtime:
     *
     *  - every flat key still null after the merge is back-filled from its
     *    nested counterpart (an explicitly set flat key keeps winning for
     *    legacy runtime overrides), and
     *  - `gaze.safety_net` itself is collapsed back to the bool enable
     *    switch, which is what every pre-existing reader —
     *    `gaze:daemon:serve`, `gaze:doctor`, adopter code — treats it as.
     *
     * A pre-v0.13 published config (flat keys, bool `safety_net`) skips this
     * entirely. The flat keys are deprecated; see UPGRADING.md.
     */
    private function normalizeSafetyNetConfig(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');
        $group = $config->get('gaze.safety_net');

        if (! is_array($group)) {
            return;
        }

        $flatFromNested = [
            'safety_net_backend' => $group['backend'] ?? null,
            'safety_net_device' => $group['device'] ?? null,
            'safety_net_timeout_ms' => $group['timeout_ms'] ?? null,
            'safety_net_input_limit_bytes' => $group['input_limit_bytes'] ?? null,
            'safety_net_mode' => $group['mode'] ?? null,
            'safety_net_fallback' => $group['fallback'] ?? null,
            'openai_filter_command' => $group['openai_filter']['command'] ?? null,
            'openai_filter_checkpoint' => $group['openai_filter']['checkpoint'] ?? null,
            'openai_filter_operating_point' => $group['openai_filter']['operating_point'] ?? null,
            'kiji_backend' => $group['kiji']['backend'] ?? null,
            'kiji_distilbert_precision' => $group['kiji']['distilbert_precision'] ?? null,
            'kiji_distilbert_command' => $group['kiji']['distilbert_command'] ?? null,
            'kiji_distilbert_model_dir' => $group['kiji']['distilbert_model_dir'] ?? null,
        ];

        foreach ($flatFromNested as $flatKey => $value) {
            if ($value !== null && $config->get('gaze.'.$flatKey) === null) {
                $config->set('gaze.'.$flatKey, $value);
            }
        }

        $config->set('gaze.safety_net', (bool) ($group['enabled'] ?? false));
    }

    public function boot(): void
    {
        if (class_exists(AboutCommand::class)) {
            // Lazy closure — only evaluated when `artisan about` actually
            // runs. Reports the pinned version rather than spawning
            // `gaze --version`: there is no cached installed-version value,
            // and `about` must stay cheap (gaze:doctor probes the real
            // binary and flags pin mismatches).
            AboutCommand::add('Gaze', function (): array {
                /** @var ConfigRepository $config */
                $config = $this->app->make('config');
                $policyPath = $config->get('gaze.policy_path');

                return [
                    'Binary' => $this->app->make(BinaryResolver::class)->resolveOrNull() ?? '<not installed>',
                    'Binary Pin' => BinaryDownloader::PINNED_VERSION,
                    'Policy Path' => is_string($policyPath) && $policyPath !== ''
                        ? $policyPath
                        : $this->app->basePath('policy.toml'),
                    'Safety Net' => $config->get('gaze.safety_net') ? 'ON' : 'OFF',
                ];
            });
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/gaze.php' => $this->app->configPath('gaze.php'),
            ], 'gaze-config');
            $this->publishes([
                __DIR__.'/../resources/policy.toml' => $this->app->basePath('policy.toml'),
            ], 'gaze-policy');

            $this->commands([
                AuditPurgeCommand::class,
                CheckCommand::class,
                DoctorCommand::class,
                CanaryCommand::class,
                BenchCommand::class,
                InstallCommand::class,
                InstallBinaryCommand::class,
                InstallSafetyNetCommand::class,
                InstallNerCommand::class,
                ProxyServeCommand::class,
                ProxyStartCommand::class,
                ProxyStopCommand::class,
                ProxyRestartCommand::class,
                ProxyStatusCommand::class,
                ProxyLogsCommand::class,
                DaemonServeCommand::class,
                DaemonStatusCommand::class,
            ]);
        }
    }
}

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
use CertaMesh\Gaze\Daemon\DaemonArgv;
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
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Encryption\Encrypter;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GazeServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gaze.php', 'gaze');

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
            $rawAuditDbPath = $config->get('gaze.audit_db_path');

            return new Gaze(
                resolver: $app->make(BinaryResolver::class),
                process: $app->make(ProcessFactory::class),
                timeoutSeconds: (int) $config->get('gaze.timeout_seconds', 30),
                policyPath: (string) $config->get('gaze.policy_path', $app->basePath('policy.toml')),
                maxBytes: is_numeric($config->get('gaze.max_bytes')) ? (int) $config->get('gaze.max_bytes') : null,
                sessionTtlSeconds: is_numeric($config->get('gaze.session_ttl_seconds')) ? (int) $config->get('gaze.session_ttl_seconds') : null,
                auditDbPath: is_string($rawAuditDbPath) && $rawAuditDbPath !== '' ? $rawAuditDbPath : null,
                sessionScope: is_string($config->get('gaze.session_scope')) && $config->get('gaze.session_scope') !== '' ? $config->get('gaze.session_scope') : null,
                locale: is_string($config->get('gaze.locale')) && $config->get('gaze.locale') !== '' ? $config->get('gaze.locale') : null,
                rulepacks: self::stringList($config->get('gaze.rulepacks')),
                rulepackPaths: self::stringList($config->get('gaze.rulepack_paths')),
                safetyNet: (bool) $config->get('gaze.safety_net', false),
                safetyNetDevice: is_string($config->get('gaze.safety_net_device')) && $config->get('gaze.safety_net_device') !== '' ? $config->get('gaze.safety_net_device') : null,
                openaiFilterCommand: is_string($config->get('gaze.openai_filter_command')) && $config->get('gaze.openai_filter_command') !== '' ? $config->get('gaze.openai_filter_command') : null,
                openaiFilterCheckpoint: is_string($config->get('gaze.openai_filter_checkpoint')) && $config->get('gaze.openai_filter_checkpoint') !== '' ? $config->get('gaze.openai_filter_checkpoint') : null,
                openaiFilterOperatingPoint: is_string($config->get('gaze.openai_filter_operating_point')) && $config->get('gaze.openai_filter_operating_point') !== '' ? $config->get('gaze.openai_filter_operating_point') : null,
                safetyNetTimeoutMs: is_numeric($config->get('gaze.safety_net_timeout_ms')) ? (int) $config->get('gaze.safety_net_timeout_ms') : null,
                safetyNetInputLimitBytes: is_numeric($config->get('gaze.safety_net_input_limit_bytes')) ? (int) $config->get('gaze.safety_net_input_limit_bytes') : null,
                safetyNetMode: is_string($config->get('gaze.safety_net_mode')) && $config->get('gaze.safety_net_mode') !== '' ? $config->get('gaze.safety_net_mode') : null,
                safetyNetBackend: is_string($config->get('gaze.safety_net_backend')) && $config->get('gaze.safety_net_backend') !== '' ? $config->get('gaze.safety_net_backend') : null,
                kijiBackend: is_string($config->get('gaze.kiji_backend')) && $config->get('gaze.kiji_backend') !== '' ? $config->get('gaze.kiji_backend') : null,
                kijiDistilbertPrecision: is_string($config->get('gaze.kiji_distilbert_precision')) && $config->get('gaze.kiji_distilbert_precision') !== '' ? $config->get('gaze.kiji_distilbert_precision') : null,
                kijiDistilbertCommand: is_string($config->get('gaze.kiji_distilbert_command')) && $config->get('gaze.kiji_distilbert_command') !== '' ? $config->get('gaze.kiji_distilbert_command') : null,
                kijiDistilbertModelDir: is_string($config->get('gaze.kiji_distilbert_model_dir')) && $config->get('gaze.kiji_distilbert_model_dir') !== '' ? $config->get('gaze.kiji_distilbert_model_dir') : null,
                safetyNetFallback: is_string($config->get('gaze.safety_net_fallback')) && $config->get('gaze.safety_net_fallback') !== '' ? $config->get('gaze.safety_net_fallback') : null,
                restoreMode: is_string($config->get('gaze.restore_mode')) && $config->get('gaze.restore_mode') !== '' ? $config->get('gaze.restore_mode') : null,
                restoreTelemetry: (bool) $config->get('gaze.restore_telemetry', false),
                nerThreshold: is_numeric($config->get('gaze.ner_threshold')) ? (float) $config->get('gaze.ner_threshold') : null,
                container: $app,
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

            // Shared assembler (also backs `gaze:daemon:serve`) — full
            // upstream flag parity on the facade spawn path, by construction.
            $flags = DaemonArgv::flags($config);

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

    public function boot(): void
    {
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

    /**
     * @return list<class-string|string>
     */
    public function provides(): array
    {
        return [
            BinaryDownloader::class,
            BinaryResolver::class,
            Gaze::class,
            GazeContract::class,
            AuditService::class,
            AuditServiceContract::class,
            DaemonClientContract::class,
            DaemonManager::class,
            DaemonManagerContract::class,
            'gaze.http_client',
            LaravelNerFetcher::class,
            NerFetcher::class,
            NerManifest::class,
            NerInstaller::class,
            PolicyTomlPatcher::class,
            SafetyNetConfigurator::class,
            'gaze.encrypter',
        ];
    }

    /**
     * Normalize a config value to the non-empty list of strings the Gaze
     * constructor expects, or null when the key is unset/empty. Non-string
     * entries are dropped — they were already outside the declared
     * list<string> contract and would only corrupt the CLI arguments built
     * from them.
     *
     * @return non-empty-list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $strings === [] ? null : $strings;
    }
}

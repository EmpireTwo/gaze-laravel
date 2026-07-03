<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Console;

use CertaMesh\Gaze\BinaryResolver;
use CertaMesh\Gaze\Exceptions\GazeBinaryMissingException;
use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\KijiArtifacts;
use Devium\Toml\Toml;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Process\Factory as ProcessFactory;

final class DoctorCommand extends Command
{
    protected $signature = 'gaze:doctor {--deep : Run a clean/restore smoke test}';

    protected $description = 'Verify binary, policy, encrypter, and optional round-trip readiness.';

    public function handle(BinaryResolver $resolver, ProcessFactory $process, ConfigRepository $config, Gaze $gaze): int
    {
        try {
            $binary = $resolver->resolve();
        } catch (GazeBinaryMissingException $e) {
            $this->components->twoColumnDetail('binary', '<fg=red>missing</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('binary', $binary);

        $version = $process->newPendingProcess()->timeout(5)->run([$binary, '--version']);
        if (! $version->successful()) {
            $this->components->twoColumnDetail('version', '<fg=red>unknown</>');
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('version', trim($version->output()));
        $this->warnOnPinnedVersionMismatch($version->output(), $config);

        $policy = (string) $config->get('gaze.policy_path', '');
        $this->components->twoColumnDetail('policy', is_file($policy) ? $policy : '<fg=red>missing</>');
        if (! is_file($policy)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->warnIfDeprecatedRulepack($config, $policy);
        $this->probeProxyFeature($binary, $config, $process);
        $this->probeDaemonFeature($binary, $config, $process);
        $this->probeRestoreTelemetry($config);
        if (! $this->probeKijiArtifacts($config)) {
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        try {
            $this->laravel->make('gaze.encrypter');
        } catch (\Throwable $e) {
            $this->components->twoColumnDetail('encrypter', '<fg=red>invalid</>');
            $this->line($e->getMessage());
            $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('encrypter', '<fg=green>OK</>');
        $this->components->twoColumnDetail('max_bytes', (string) ($config->get('gaze.max_bytes') ?? 10485760));
        $this->components->twoColumnDetail('session_ttl_seconds', (string) ($config->get('gaze.session_ttl_seconds') ?? 86400));

        if ($this->option('deep')) {
            $session = $gaze->clean('doctor@example.com');
            $restored = $gaze->restore($session, $session->cleanText);

            if (! str_contains($restored, 'doctor@example.com')) {
                $this->components->twoColumnDetail('deep', '<fg=red>FAIL</>');
                $this->components->twoColumnDetail('status', '<fg=red>FAIL</>');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('deep', '<fg=green>OK</>');
        }

        $this->components->twoColumnDetail('status', '<fg=green>OK</>');

        return self::SUCCESS;
    }

    /**
     * P7 doctor-before-failure: surface the post-#139 gap where an installed
     * binary silently lags the package's pinned version after an upgrade.
     *
     * The Composer plugin that re-installed the binary on `composer update` was
     * removed in v0.13.0, so `gaze:install --force` is the only refresh path. A
     * package upgrade that bumps {@see BinaryDownloader::PINNED_VERSION} now
     * leaves the old binary in place with no signal until a runtime feature is
     * missing. Doctor closes that gap here.
     *
     * WARN, never FAIL — a deliberately held-back binary is legitimate, so this
     * follows the warn-without-exit-flip convention of the proxy / daemon /
     * restore-telemetry probes (none of them flip the exit code either):
     *   - `GAZE_BINARY` points at an adopter-built binary (source builds,
     *     unsupported platforms) — the GitHub-release pin does not apply.
     *   - `GAZE_VERSION` pins a different version on purpose.
     * When either opt-out is set the message softens to "expected" and drops the
     * `--force` hint, since the adopter has explicitly opted out of the pin.
     */
    private function warnOnPinnedVersionMismatch(string $versionOutput, ConfigRepository $config): void
    {
        $reported = BinaryDownloader::parseVersion($versionOutput);
        $pinned = BinaryDownloader::PINNED_VERSION;

        // Unparseable output is not this probe's concern (the version detail
        // already shows the raw string); a match is silent, like the other
        // problem-only probes.
        if ($reported === null || $reported === $pinned) {
            return;
        }

        $binary = $config->get('gaze.binary');
        $explicitBinary = is_string($binary) && $binary !== '';
        $explicitVersion = ($env = getenv('GAZE_VERSION')) !== false && $env !== '';

        if ($explicitBinary || $explicitVersion) {
            $optOut = $explicitBinary ? 'GAZE_BINARY' : 'GAZE_VERSION';
            $this->components->twoColumnDetail(
                'pinned version',
                "<fg=yellow>{$reported} (pin v{$pinned}, {$optOut} set)</>"
            );
            $this->warn(
                "gaze binary reports v{$reported} but this package pins v{$pinned}. "
                ."{$optOut} is set, so this mismatch is expected — you have opted out "
                .'of the pin. Ignore if intentional.'
            );

            return;
        }

        $this->components->twoColumnDetail(
            'pinned version',
            "<fg=yellow>{$reported} (expected v{$pinned})</>"
        );
        $this->warn(
            "gaze binary reports v{$reported} but this package pins v{$pinned}. "
            .'A package upgrade bumped the pin without refreshing the binary '
            .'(the Composer auto-install plugin was removed in v0.13.0).'
        );
        // Keep the actionable hint on its own short line so it survives console
        // width-wrapping (a wrapped long line would split the command token).
        $this->warn('Run `php artisan gaze:install --force` to install the pinned binary.');
    }

    private function warnIfDeprecatedRulepack(ConfigRepository $config, string $policyPath): void
    {
        $message = "rulepack 'core-extended' is deprecated as of gaze v0.8.0; aliases to 'core' with a runtime warning. Upstream still ships this soft alias through v0.11.x; removal is deferred (no firm target announced). Pass an explicit --locale (or set GAZE_LOCALE) to retain phone.national.* / postal.* coverage.";

        $rulepacks = $config->get('gaze.rulepacks');
        if (is_array($rulepacks) && in_array('core-extended', $rulepacks, true)) {
            $this->warn($message);

            return;
        }

        try {
            $body = file_get_contents($policyPath);
            if ($body === false) {
                throw new \RuntimeException('could not read file');
            }

            /** @var array<string, mixed> $parsed */
            $parsed = Toml::decode($body, asArray: true);
        } catch (\Throwable $e) {
            $this->warn(
                "policy at {$policyPath} could not be parsed as TOML ({$e->getMessage()}) — "
                .'skipping deprecated-rulepack check. The gaze binary will likely reject this policy too.'
            );

            return;
        }

        $bundled = $parsed['policy']['rulepacks']['bundled'] ?? null;
        if (is_array($bundled) && in_array('core-extended', $bundled, true)) {
            $this->warn($message);
        }
    }

    /**
     * Best-effort probe for the upstream `proxy` feature build flag.
     *
     * Skipped when the adopter has not deviated from the package's default
     * `gaze.proxy.*` block — keeps doctor output noise-free for the majority
     * who never use the proxy. When the adopter HAS configured proxy and the
     * installed binary lacks the feature, surface the exact `cargo install`
     * hint upstream documents.
     */
    private function probeProxyFeature(string $binary, ConfigRepository $config, ProcessFactory $process): void
    {
        if (! $this->proxyExplicitlyConfigured($config)) {
            return;
        }

        $result = $process->newPendingProcess()->timeout(3)->run([$binary, 'proxy', '--help']);
        $stderr = $result->errorOutput();

        if ($result->successful() && ! str_contains($stderr, 'unknown subcommand')) {
            $this->info('gaze proxy feature available');

            return;
        }

        $this->warn(
            'gaze proxy not available — rebuild upstream binary with: '
            .'cargo install gaze-cli --features proxy. '
            .'Adapter v0.8.1 proxy artisan commands will error on invocation.'
        );
    }

    /**
     * Pre-flight probe for the upstream `daemon` feature build flag.
     *
     * Skipped when `gaze.daemon.policy_path` is null — that key is the
     * opt-in signal that the adopter intends to use daemon mode. When
     * populated, surfaces the exact `cargo install` hint if the binary
     * lacks the subverb.
     */
    private function probeDaemonFeature(string $binary, ConfigRepository $config, ProcessFactory $process): void
    {
        $policyPath = $config->get('gaze.daemon.policy_path');
        if (! is_string($policyPath) || $policyPath === '') {
            return;
        }

        if (! is_file($policyPath)) {
            $this->components->twoColumnDetail('daemon policy', '<fg=red>missing</>');
            $this->warn("gaze.daemon.policy_path={$policyPath} does not exist.");
        } else {
            $this->components->twoColumnDetail('daemon policy', $policyPath);
        }

        $auditDb = $config->get('gaze.daemon.audit_db_path');
        if (is_string($auditDb) && $auditDb !== '') {
            $parent = dirname($auditDb);
            if (! is_dir($parent) || ! is_writable($parent)) {
                $this->warn("gaze.daemon.audit_db_path parent {$parent} is not writable.");
            }
        }

        $stderrPath = $config->get('gaze.daemon.stderr_path');
        if (is_string($stderrPath) && $stderrPath !== '') {
            $parent = dirname($stderrPath);
            if (! is_dir($parent) || ! is_writable($parent)) {
                $this->warn("gaze.daemon.stderr_path parent {$parent} is not writable.");
            }
        }

        $result = $process->newPendingProcess()->timeout(3)->run([$binary, 'daemon', '--help']);
        $stderr = $result->errorOutput();

        if ($result->successful() && ! str_contains($stderr, 'unknown subcommand')) {
            $this->info('gaze daemon feature available');

            return;
        }

        $this->warn(
            'gaze daemon not available — rebuild upstream binary with: '
            .'cargo install gaze-cli --features daemon. '
            .'Adapter v0.11.0 daemon artisan commands and Gaze::daemon() Facade will error on invocation.'
        );
    }

    /**
     * Axis-1 fail-closed pre-flight for the Kiji DistilBERT backend.
     *
     * Skipped silently unless `gaze.safety_net_backend === 'kiji-distilbert'`
     * — the openai-filter backend ships its own pre-flight upstream and the
     * default null backend selector means "let upstream choose" so we have
     * no Kiji-specific contract to enforce.
     *
     * When the adopter HAS opted into Kiji, surface the same artifact
     * requirements upstream enforces (`SHA256SUMS`, `labels.json`,
     * `model.onnx`, `tokenizer.json`) before the binary fails the first
     * `gaze clean` with a `SafetyNetArtifactMissing` envelope. Returns true
     * on pass; false signals doctor should exit FAILURE.
     */
    private function probeKijiArtifacts(ConfigRepository $config): bool
    {
        $backend = $config->get('gaze.safety_net_backend');
        if ($backend !== 'kiji-distilbert') {
            return true;
        }

        $dir = $config->get('gaze.kiji_distilbert_model_dir');
        if (! is_string($dir) || $dir === '') {
            $this->components->twoColumnDetail('kiji_distilbert', '<fg=red>missing model_dir</>');
            $this->warn(
                'gaze.safety_net_backend=kiji-distilbert requires gaze.kiji_distilbert_model_dir '
                .'(or GAZE_KIJI_DISTILBERT_MODEL_DIR) to point at the pinned model directory. '
                .'Fetch it with upstream scripts/fetch-kiji-safetynet-model.sh.'
            );

            return false;
        }

        // Single source of truth shared with gaze:install:safety-net (CB4): the
        // pre-write gate and this post-write probe validate the same artifacts.
        $missing = KijiArtifacts::missing($dir);

        if ($missing !== []) {
            $this->components->twoColumnDetail(
                'kiji_distilbert',
                '<fg=red>missing: '.implode(', ', $missing).'</>'
            );
            $this->warn(
                'gaze.kiji_distilbert_model_dir is missing required artifacts ('
                .implode(', ', $missing).'). Re-fetch with upstream '
                .'scripts/fetch-kiji-safetynet-model.sh; the dir must carry 0o700 '
                .'permissions and each file 0o600.'
            );

            return false;
        }

        $this->components->twoColumnDetail('kiji_distilbert', '<fg=green>OK</>');

        return true;
    }

    /**
     * Pre-flight probe for restore-telemetry audit-db writability.
     *
     * Skipped silently unless `gaze.restore_telemetry` is enabled — that key is
     * the opt-in signal (P7 doctor-before-failure, but only when opted in). When
     * enabled, asserts `gaze.audit_db_path` is set and its parent dir is
     * writable; WARNS (never hard-fails) when missing/unwritable so the adopter
     * learns restore-telemetry rows cannot be written before the first restore.
     */
    private function probeRestoreTelemetry(ConfigRepository $config): void
    {
        if (! $config->get('gaze.restore_telemetry')) {
            return;
        }

        $auditDb = $config->get('gaze.audit_db_path');
        if (! is_string($auditDb) || $auditDb === '') {
            $this->components->twoColumnDetail('restore_telemetry', '<fg=yellow>no audit-db</>');
            $this->warn(
                'gaze.restore_telemetry is enabled but gaze.audit_db_path (env '
                .'GAZE_AUDIT_DB_PATH) is not set — restore-telemetry rows cannot be '
                .'written. Set the audit-db path to capture them.'
            );

            return;
        }

        $parent = dirname($auditDb);
        if (! is_dir($parent) || ! is_writable($parent)) {
            $this->components->twoColumnDetail('restore_telemetry', '<fg=yellow>unwritable</>');
            $this->warn(
                "gaze.audit_db_path parent {$parent} is not writable — "
                .'restore-telemetry rows cannot be written.'
            );

            return;
        }

        $this->components->twoColumnDetail('restore_telemetry', '<fg=green>OK</>');
    }

    private function proxyExplicitlyConfigured(ConfigRepository $config): bool
    {
        $proxy = $config->get('gaze.proxy');
        if (! is_array($proxy)) {
            return false;
        }

        $policyPath = $proxy['policy_path'] ?? null;
        if (is_string($policyPath) && $policyPath !== '') {
            return true;
        }

        $defaults = [
            'bind' => '127.0.0.1:8787',
            'session_ttl' => '30m',
            'rulepack' => 'core',
            'stop_timeout' => '10s',
        ];
        foreach ($defaults as $key => $default) {
            if (($proxy[$key] ?? $default) !== $default) {
                return true;
            }
        }

        $upstreamDefaults = [
            'openai' => 'https://api.openai.com/',
            'anthropic' => 'https://api.anthropic.com/',
            'gemini' => 'https://generativelanguage.googleapis.com/',
        ];
        $upstream = $proxy['upstream'] ?? [];
        if (! is_array($upstream)) {
            return false;
        }
        foreach ($upstreamDefaults as $key => $default) {
            if (($upstream[$key] ?? $default) !== $default) {
                return true;
            }
        }

        return false;
    }
}

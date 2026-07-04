<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Audit\AuditService;
use CertaMesh\Gaze\Contracts\AuditRunner;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\DaemonManager;
use CertaMesh\Gaze\Exceptions\GazeEmptyInputException;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeInputTooLargeException;
use CertaMesh\Gaze\Exceptions\GazeInvalidEncodingException;
use CertaMesh\Gaze\Exceptions\GazeResponseDecodeException;
use CertaMesh\Gaze\Exceptions\GazeTimeoutException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;

class Gaze implements AuditRunner, GazeContract
{
    private const DEFAULT_MAX_BYTES = 10485760;

    public function __construct(
        private readonly BinaryResolver $resolver,
        private readonly ProcessFactory $process,
        private readonly int $timeoutSeconds,
        private readonly Container $container,
        private readonly ?string $policyPath = null,
        private readonly ?int $maxBytes = null,
        private readonly ?int $sessionTtlSeconds = null,
        private readonly ?string $auditDbPath = null,
        private readonly ?string $locale = null,
        /** @var list<string>|null */
        private readonly ?array $rulepacks = null,
        /** @var list<string>|null */
        private readonly ?array $rulepackPaths = null,
        private readonly bool $safetyNet = false,
        private readonly ?string $safetyNetDevice = null,
        private readonly ?string $openaiFilterCommand = null,
        private readonly ?string $openaiFilterCheckpoint = null,
        private readonly ?string $openaiFilterOperatingPoint = null,
        private readonly ?int $safetyNetTimeoutMs = null,
        private readonly ?int $safetyNetInputLimitBytes = null,
        private readonly ?string $safetyNetMode = null,
        private readonly ?string $safetyNetBackend = null,
        private readonly ?string $kijiBackend = null,
        private readonly ?string $kijiDistilbertPrecision = null,
        private readonly ?string $kijiDistilbertCommand = null,
        private readonly ?string $kijiDistilbertModelDir = null,
        private readonly ?string $safetyNetFallback = null,
        private readonly ?string $sessionScope = null,
        private readonly ?string $restoreMode = null,
        private readonly bool $restoreTelemetry = false,
        private readonly ?float $nerThreshold = null,
    ) {}

    public function clean(string $text, ?float $threshold = null): GazeSession
    {
        $this->assertInput($text);

        $effectiveThreshold = $this->resolveNerThreshold($threshold);

        $command = [
            $this->resolver->resolve(),
            'clean',
            '--policy='.$this->resolvedPolicyPath(),
            '--format=json',
        ];

        if ($effectiveThreshold !== null) {
            $command[] = '--ner-threshold='.$effectiveThreshold;
        }

        if ($this->maxBytes !== null) {
            $command[] = '--max-bytes='.$this->maxBytes;
        }

        if ($this->sessionTtlSeconds !== null) {
            $command[] = '--session-ttl='.$this->sessionTtlSeconds;
        }

        if ($this->sessionScope !== null && $this->sessionScope !== '') {
            $command[] = '--session-scope='.$this->sessionScope;
        }

        if ($this->auditDbPath !== null && $this->auditDbPath !== '') {
            $command[] = '--audit-db='.$this->auditDbPath;
        }

        if ($this->locale !== null && $this->locale !== '') {
            $command[] = '--locale='.$this->locale;
        }

        foreach ($this->rulepacks ?? [] as $rulepack) {
            $command[] = '--rulepack-bundled='.$rulepack;
        }

        foreach ($this->rulepackPaths ?? [] as $path) {
            $command[] = '--rulepack-path='.$path;
        }

        if ($this->safetyNet) {
            $command[] = '--safety-net=openai-filter';
        }

        if ($this->safetyNetDevice !== null && $this->safetyNetDevice !== '') {
            $command[] = '--openai-filter-device='.$this->safetyNetDevice;
        }

        if ($this->openaiFilterCommand !== null && $this->openaiFilterCommand !== '') {
            $command[] = '--openai-filter-command='.$this->openaiFilterCommand;
        }

        if ($this->openaiFilterCheckpoint !== null && $this->openaiFilterCheckpoint !== '') {
            $command[] = '--openai-filter-checkpoint='.$this->openaiFilterCheckpoint;
        }

        if ($this->openaiFilterOperatingPoint !== null && $this->openaiFilterOperatingPoint !== '') {
            $command[] = '--openai-filter-operating-point='.$this->openaiFilterOperatingPoint;
        }

        if ($this->safetyNetTimeoutMs !== null) {
            $command[] = '--safety-net-timeout-ms='.$this->safetyNetTimeoutMs;
        }

        if ($this->safetyNetInputLimitBytes !== null) {
            $command[] = '--safety-net-input-limit-bytes='.$this->safetyNetInputLimitBytes;
        }

        if ($this->safetyNetMode !== null && $this->safetyNetMode !== '') {
            $command[] = '--safety-net-mode='.$this->safetyNetMode;
        }

        if ($this->safetyNetBackend !== null && $this->safetyNetBackend !== '') {
            $command[] = '--safety-net-backend='.$this->safetyNetBackend;
        }

        if ($this->kijiBackend !== null && $this->kijiBackend !== '') {
            $command[] = '--kiji-backend='.$this->kijiBackend;
        }

        if ($this->kijiDistilbertPrecision !== null && $this->kijiDistilbertPrecision !== '') {
            $command[] = '--kiji-distilbert-precision='.$this->kijiDistilbertPrecision;
        }

        if ($this->kijiDistilbertCommand !== null && $this->kijiDistilbertCommand !== '') {
            $command[] = '--kiji-distilbert-command='.$this->kijiDistilbertCommand;
        }

        if ($this->kijiDistilbertModelDir !== null && $this->kijiDistilbertModelDir !== '') {
            $command[] = '--kiji-distilbert-model-dir='.$this->kijiDistilbertModelDir;
        }

        if ($this->safetyNetFallback !== null && $this->safetyNetFallback !== '') {
            $command[] = '--safety-net-fallback='.$this->safetyNetFallback;
        }

        $result = $this->run($command, $text, 'clean');

        /** @var array{clean_text:string,session_blob:string,stats?:array{detections?:int},entries?:list<array<string,mixed>>,leak_report?:array<string,mixed>} $decoded */
        $decoded = $this->decodeResponse($result->output(), 'clean');

        return new GazeSession(
            cleanText: $decoded['clean_text'],
            ciphertext: EncryptedBlob::wrap($decoded['session_blob']),
            detections: (int) ($decoded['stats']['detections'] ?? 0),
            entries: $this->mapEntries($decoded['entries'] ?? null),
            leakReport: $this->mapLeakReport($decoded['leak_report'] ?? null),
        );
    }

    /**
     * Map the optional `leak_report` field of the gaze CLI clean response into a
     * LeakReport DTO. Returns null when the field is absent or not an object —
     * a null report degrades the session's trust state to Unverified rather than
     * silently asserting Verified. Never throws on shape drift.
     */
    private function mapLeakReport(mixed $raw): ?LeakReport
    {
        return is_array($raw) ? LeakReport::fromArray($raw) : null;
    }

    /**
     * One-way redaction helper: run the clean detection path, then replace
     * every detected token in the clean text with a masked label.
     *
     * Unlike clean()/restore(), mask() is NON-reversible — the encrypted
     * session blob is discarded and there is no restore() counterpart. Reach
     * for clean() when the original values must round-trip back; reach for
     * mask() only when they must be permanently dropped.
     *
     * The label defaults to `[<class>]` (e.g. `[Email]`). Pass $replace to
     * customise it; the callable receives the matching Entry and returns the
     * replacement string. Tokens are unique per detection, so the str_replace
     * sweep is collision-safe.
     *
     * Adds NO detection of its own — it only reshapes the inventory clean()
     * already produced (detection stays upstream).
     *
     * @param  (callable(Entry): string)|null  $replace
     */
    public function mask(string $text, ?callable $replace = null): string
    {
        $session = $this->clean($text);

        $masked = $session->cleanText;
        foreach ($session->entries as $entry) {
            $label = $replace !== null ? $replace($entry) : '['.$entry->class.']';
            $masked = str_replace($entry->token, $label, $masked);
        }

        return $masked;
    }

    /**
     * Resolve the effective NER threshold for a clean() call. The per-call
     * argument wins over the configured `gaze.ner_threshold` default; null at
     * both levels lets upstream apply its own policy `[ner]` threshold.
     *
     * @throws \InvalidArgumentException when the effective value falls outside
     *                                   the inclusive 0.0–1.0 range upstream accepts
     */
    private function resolveNerThreshold(?float $threshold): ?float
    {
        $effective = $threshold ?? $this->nerThreshold;

        if ($effective !== null && ($effective < 0.0 || $effective > 1.0)) {
            throw new \InvalidArgumentException(
                "gaze ner_threshold must be between 0.0 and 1.0 inclusive, got {$effective}."
            );
        }

        return $effective;
    }

    /**
     * Map the optional `entries` field of the gaze CLI clean response into
     * a list of Entry DTOs. Returns [] when the field is absent, null, or
     * not a list of associative arrays — never throws on shape drift.
     *
     * @return list<Entry>
     */
    private function mapEntries(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $entries = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $entries[] = Entry::fromArray($item);
            }
        }

        return $entries;
    }

    public function restore(GazeSession $session, string $text): string
    {
        $this->assertInput($text);

        try {
            $sessionBlob = $session->ciphertext->decryptedBlob();
        } catch (DecryptException $e) {
            $exception = new GazeResponseDecodeException(
                'gaze restore session blob could not be decrypted (exit=-1, stderr_sha256=none)',
                exitCode: -1,
                stderrHash: null,
                previous: $e,
            );
            Log::notice('gaze restore failed', $exception->toLogContext());

            throw $exception;
        }

        $payload = json_encode([
            'session_blob' => $sessionBlob,
            'text' => $text,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertInputSize($payload);

        $command = [$this->resolver->resolve(), 'restore', '--format=json'];
        if ($this->maxBytes !== null) {
            $command[] = '--max-bytes='.$this->maxBytes;
        }

        if ($this->restoreMode !== null && $this->restoreMode !== '') {
            $command[] = '--restore-mode='.$this->restoreMode;
        }

        // Restore-decision telemetry: forward --telemetry so the binary records
        // restore-decision / unknown-token audit rows, plus --audit-db when an
        // audit sink is configured (telemetry with no audit-db still forwards
        // --telemetry so the binary uses its own default sink).
        //
        // CAVEAT: restore_fresh_pii_count / restore_manifest_bypass_count are
        // ALWAYS 0 through the stock gaze CLI — run_restore never enables the
        // Phase-B DLP builder. This is a restore-decision audit trail, not
        // outbound-DLP fresh-PII detection.
        if ($this->restoreTelemetry) {
            $command[] = '--telemetry';

            if ($this->auditDbPath !== null && $this->auditDbPath !== '') {
                $command[] = '--audit-db='.$this->auditDbPath;
            }
        }

        $result = $this->run($command, $payload, 'restore');

        /** @var array{text:string} $decoded */
        $decoded = $this->decodeResponse($result->output(), 'restore');

        return $decoded['text'];
    }

    public function audit(?string $auditDbPath = null): AuditService
    {
        if ($auditDbPath !== null && $auditDbPath !== '') {
            return new AuditService(
                gaze: $this,
                resolver: $this->resolver,
                auditDbPath: $auditDbPath,
            );
        }

        return $this->container->make(AuditService::class);
    }

    /**
     * Resolve the daemon manager for the long-lived `gaze daemon` runtime.
     *
     * Composition:    `Gaze::daemon()->session($id)->clean($text)`
     * Direct hot path: `Gaze::daemon()->clean($id, $text)`
     *
     * The bound `DaemonClient` is request-scoped (Octane-safe) and held by
     * the container. Sessions returned by `DaemonManager::session()` are
     * memoised per id within the request lifetime.
     */
    public function daemon(): DaemonManager
    {
        return $this->container->make(DaemonManager::class);
    }

    /**
     * @internal Audit-purge process invocation. Not a generic command runner;
     * hard-scoped to the `audit purge` stage.
     *
     * @param  list<string>  $command
     */
    public function runForAuditPurge(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit purge');
    }

    /**
     * @internal Audit-query process invocation. Not a generic command runner;
     * hard-scoped to the `audit query` stage.
     *
     * @param  list<string>  $command
     */
    public function runForAuditQuery(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit query');
    }

    /**
     * @internal Audit-export process invocation. Not a generic command runner;
     * hard-scoped to the `audit export` stage.
     *
     * @param  list<string>  $command
     */
    public function runForAuditExport(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit export');
    }

    /**
     * @internal Safety-net-query process invocation. Not a generic command
     * runner; hard-scoped to the `audit safety-net query` stage.
     *
     * @param  list<string>  $command
     */
    public function runForAuditSafetyNetQuery(array $command): ProcessResult
    {
        return $this->run($command, '', 'audit safety-net query');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $output, string $stage): array
    {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $exception = new GazeResponseDecodeException(
                "gaze {$stage} response was not valid JSON (exit=-1, stderr_sha256=none)",
                exitCode: -1,
                stderrHash: null,
                previous: $e,
            );
            Log::notice("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        if (! is_array($decoded)) {
            $exception = new GazeResponseDecodeException(
                "gaze {$stage} response was not a JSON object (exit=-1, stderr_sha256=none)",
                exitCode: -1,
                stderrHash: null,
            );
            Log::notice("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command, string $input, string $stage): ProcessResult
    {
        try {
            $result = $this->process
                ->newPendingProcess()
                ->timeout($this->timeoutSeconds)
                ->input($input)
                ->run($command);
        } catch (ProcessTimedOutException $e) {
            $exception = new GazeTimeoutException(
                "gaze {$stage} timed out (exit=-1, stderr_sha256=none)",
                exitCode: -1,
                stderrHash: null,
                previous: $e,
            );
            Log::warning("gaze {$stage} failed", $exception->toLogContext());

            throw $exception;
        }

        if ($result->successful()) {
            return $result;
        }

        throw $this->buildException($stage, $result);
    }

    private function assertInput(string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new GazeInvalidEncodingException('gaze input is not valid UTF-8', 1, null);
        }

        $this->assertInputSize($text);

        if ($text === '') {
            throw new GazeEmptyInputException('gaze input must not be empty', 1, null);
        }
    }

    private function assertInputSize(string $input): void
    {
        if (strlen($input) > ($this->maxBytes ?? self::DEFAULT_MAX_BYTES)) {
            throw new GazeInputTooLargeException('gaze input exceeds max_bytes pre-flight', 1, null);
        }
    }

    private function resolvedPolicyPath(): string
    {
        $policyPath = $this->policyPath ?? base_path('policy.toml');

        if ($policyPath === '') {
            throw new \RuntimeException('gaze.policy_path must not be empty.');
        }

        return $policyPath;
    }

    private function buildException(string $stage, ProcessResult $result): GazeException
    {
        $stderr = $result->errorOutput() ?: '';
        $exitCode = $result->exitCode() ?? -1;
        $stderrHash = hash('sha256', $stderr);

        // Empty-stderr SIGPIPE is expected when a downstream reader closes the
        // pipe early — log at debug, not at the variant's normal level.
        if ($exitCode === 141 && $stderr === '') {
            $exception = Variant::SigPipe->toException($stage, 141, $stderrHash);
            Log::debug("gaze {$stage} failed", $exception->toLogContext());

            return $exception;
        }

        // Non-empty stderr on exit 141 still goes through the normal stderr
        // safelist parser so upstream can surface a typed variant if it emits one.
        $exception = Variant::tryFromStderr($stderr, $exitCode)
            ->toException($stage, $exitCode, $stderrHash, $stderr);

        Log::{$exception->logLevel()}("gaze {$stage} failed", $exception->toLogContext());

        return $exception;
    }
}

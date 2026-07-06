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
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Log;

class Gaze implements AuditRunner, GazeContract
{
    private const DEFAULT_MAX_BYTES = 10485760;

    // Mirrors of the GazeOptions fields consumed by restore(), run(), and
    // the pre-flight helpers in the lower half of this file. clean()/mask()
    // read $this->options directly; these keep the process-invocation and
    // error paths on stable `$this->x` property reads.
    private readonly int $timeoutSeconds;

    private readonly ?int $maxBytes;

    private readonly ?string $auditDbPath;

    private readonly ?string $restoreMode;

    private readonly bool $restoreTelemetry;

    public function __construct(
        private readonly BinaryResolver $resolver,
        private readonly ProcessFactory $process,
        private readonly Container $container,
        private readonly ?string $policyPath = null,
        private readonly GazeOptions $options = new GazeOptions,
        // Session-blob encrypter (the `gaze.encrypter` binding). Injected so
        // EncryptedBlob::wrap never service-locates on the hot clean() path;
        // null falls back to lazy container resolution inside the blob.
        private readonly (EncrypterContract&StringEncrypter)|null $encrypter = null,
    ) {
        $this->timeoutSeconds = $options->timeoutSeconds;
        $this->maxBytes = $options->maxBytes;
        $this->auditDbPath = $options->auditDbPath;
        $this->restoreMode = $options->restoreMode;
        $this->restoreTelemetry = $options->restoreTelemetry;
    }

    public function clean(string $text, ?float $threshold = null): GazeSession
    {
        $this->assertInput($text);

        $command = [
            $this->resolver->resolve(),
            'clean',
            '--policy='.$this->resolvedPolicyPath(),
            '--format=json',
        ];

        // Declarative flag map, appended in DECLARATION ORDER — the argv
        // sequence is a pinned contract (ArgvAssemblyTest and friends), so
        // add new flags in the position the binary expects, not alphabetically.
        // null / '' omits a flag; a list value repeats its flag per element.
        $this->appendFlags($command, [
            '--ner-threshold' => $this->resolveNerThreshold($threshold),
            '--max-bytes' => $this->options->maxBytes,
            '--session-ttl' => $this->options->sessionTtlSeconds,
            '--session-scope' => $this->options->sessionScope,
            '--audit-db' => $this->options->auditDbPath,
            '--locale' => $this->options->locale,
            '--rulepack-bundled' => $this->options->rulepacks,
            '--rulepack-path' => $this->options->rulepackPaths,
            // v0.6.4+ contract: the enable switch must carry the backend kind;
            // a bare --safety-net is rejected by the binary.
            '--safety-net' => $this->options->safetyNet ? 'openai-filter' : null,
            '--openai-filter-device' => $this->options->safetyNetDevice,
            '--openai-filter-command' => $this->options->openaiFilterCommand,
            '--openai-filter-checkpoint' => $this->options->openaiFilterCheckpoint,
            '--openai-filter-operating-point' => $this->options->openaiFilterOperatingPoint,
            '--safety-net-timeout-ms' => $this->options->safetyNetTimeoutMs,
            '--safety-net-input-limit-bytes' => $this->options->safetyNetInputLimitBytes,
            '--safety-net-mode' => $this->options->safetyNetMode,
            '--safety-net-backend' => $this->options->safetyNetBackend,
            '--kiji-backend' => $this->options->kijiBackend,
            '--kiji-distilbert-precision' => $this->options->kijiDistilbertPrecision,
            '--kiji-distilbert-command' => $this->options->kijiDistilbertCommand,
            '--kiji-distilbert-model-dir' => $this->options->kijiDistilbertModelDir,
            '--safety-net-fallback' => $this->options->safetyNetFallback,
        ]);

        $result = $this->run($command, $text, 'clean');

        /** @var array{clean_text:string,session_blob:string,stats?:array{detections?:int},entries?:list<array<string,mixed>>,leak_report?:array<string,mixed>} $decoded */
        $decoded = $this->decodeResponse($result->output(), 'clean');

        return new GazeSession(
            cleanText: $decoded['clean_text'],
            ciphertext: EncryptedBlob::wrap($decoded['session_blob'], $this->encrypter),
            detections: (int) ($decoded['stats']['detections'] ?? 0),
            entries: $this->mapEntries($decoded['entries'] ?? null),
            leakReport: $this->mapLeakReport($decoded['leak_report'] ?? null),
        );
    }

    /**
     * Append `--flag=value` pairs to the argv in map order. A null or ''
     * value omits its flag entirely; a list value repeats the flag once per
     * element (e.g. `--rulepack-bundled=a --rulepack-bundled=b`). Numeric
     * values stringify exactly as the previous string-concatenation blocks
     * did — this helper only centralizes the append loop, not the formatting.
     *
     * @param  list<string>  $command
     * @param  array<string, int|float|string|list<string>|null>  $flags
     */
    private function appendFlags(array &$command, array $flags): void
    {
        foreach ($flags as $flag => $value) {
            foreach (is_array($value) ? $value : [$value] as $single) {
                if ($single === null || $single === '') {
                    continue;
                }

                $command[] = $flag.'='.$single;
            }
        }
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
        $effective = $threshold ?? $this->options->nerThreshold;

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

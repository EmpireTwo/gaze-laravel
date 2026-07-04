<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\Gaze as GazeContract;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\EncryptedBlob;
use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\GazeSession;

/**
 * Test double for the `Gaze` service. Implements `Contracts\Gaze` directly
 * (it does NOT extend the concrete, process-invoking `CertaMesh\Gaze\Gaze`)
 * so its surface is exactly the public contract — no inherited internals
 * that would fatal on an uninitialized constructor state.
 *
 * Configure behaviour fluently off `Gaze::fake()` (each method returns the
 * fake, so calls chain):
 *
 *     Gaze::fake()
 *         ->cleanUsing(fn (string $text, ?float $threshold) => ...)
 *         ->restoreUsing(fn (GazeSession $session, string $text) => ...)
 *         ->failWith(new GazeException('boom', exitCode: 70, stderrHash: 'x'));
 *
 * The positional-closure constructor remains supported; a fluent call
 * simply replaces the corresponding handler.
 */
final class FakeGaze implements GazeContract
{
    /** @var list<array{text: string, threshold: float|null}> */
    private array $cleanCalls = [];

    /** @var list<array{text: string}> */
    private array $maskCalls = [];

    /** @var list<array{text: string, clean_text: string}> */
    private array $restoreCalls = [];

    private ?\Closure $maskHandler = null;

    private ?GazeException $failure = null;

    private readonly FakeAuditService $auditService;

    private readonly FakeDaemonManager $daemonManager;

    /**
     * @param  \Closure(string, ?float): GazeSession|null  $cleanHandler
     * @param  \Closure(GazeSession, string): string|null  $restoreHandler
     * @param  \Closure(string, bool): AuditPurgeResult|null  $auditPurgeHandler
     * @param  \Closure(string, string): CleanResponse|null  $daemonCleanHandler
     * @param  \Closure(string|null, string): AuditExportResult|null  $auditExportHandler
     */
    public function __construct(
        private ?\Closure $cleanHandler = null,
        private ?\Closure $restoreHandler = null,
        ?\Closure $auditPurgeHandler = null,
        ?\Closure $daemonCleanHandler = null,
        ?\Closure $auditExportHandler = null,
    ) {
        $this->auditService = new FakeAuditService($auditPurgeHandler, $auditExportHandler);
        $this->daemonManager = new FakeDaemonManager($daemonCleanHandler);
    }

    /**
     * Script the return value of every `clean()` call.
     *
     * @param  \Closure(string, ?float): GazeSession  $handler
     */
    public function cleanUsing(\Closure $handler): static
    {
        $this->cleanHandler = $handler;

        return $this;
    }

    /**
     * Script the return value of every `mask()` call. When set, `mask()`
     * no longer re-enters `clean()` — the handler owns the whole result.
     *
     * @param  \Closure(string, ?callable): string  $handler
     */
    public function maskUsing(\Closure $handler): static
    {
        $this->maskHandler = $handler;

        return $this;
    }

    /**
     * Script the return value of every `restore()` call.
     *
     * @param  \Closure(GazeSession, string): string  $handler
     */
    public function restoreUsing(\Closure $handler): static
    {
        $this->restoreHandler = $handler;

        return $this;
    }

    /**
     * Simulate a broken binary: every redaction verb — `clean()`, `mask()`,
     * `restore()`, and `daemon()->clean()` — throws the given exception
     * after recording the call, so failure-path tests can still assert the
     * service was reached. Takes precedence over any scripted handler.
     * Audit verbs are unaffected; script those via `auditPurgeUsing()` /
     * `auditExportUsing()` with a throwing closure if needed.
     */
    public function failWith(GazeException $exception): static
    {
        $this->failure = $exception;
        $this->daemonManager->failWith($exception);

        return $this;
    }

    /**
     * Script the result of every `audit()->purge()->…->execute()` or
     * `->dryRun()` call.
     *
     * @param  \Closure(string, bool): AuditPurgeResult  $handler
     */
    public function auditPurgeUsing(\Closure $handler): static
    {
        $this->auditService->purgeUsing($handler);

        return $this;
    }

    /**
     * Script the result of every `audit()->query()->…->export()` call.
     *
     * @param  \Closure(string|null, string): AuditExportResult  $handler
     */
    public function auditExportUsing(\Closure $handler): static
    {
        $this->auditService->exportUsing($handler);

        return $this;
    }

    /**
     * Script the response of every `daemon()->clean()` call.
     *
     * @param  \Closure(string, string): CleanResponse  $handler
     */
    public function daemonCleanUsing(\Closure $handler): static
    {
        $this->daemonManager->cleanUsing($handler);

        return $this;
    }

    /**
     * Seed the rows `audit()->query()->…->execute()` returns. Rows use the
     * real builder's return shape — TSV positional lists, one inner list
     * per audit row. Filters remain no-op recorders: seeded rows come back
     * regardless of the filters a test applies.
     *
     * @param  list<list<string>>  $rows
     */
    public function withAuditRows(array $rows): static
    {
        $this->auditService->withQueryRows($rows);

        return $this;
    }

    /**
     * Seed the rows `audit()->safetyNetQuery()->…->execute()` returns.
     * Same shape and no-op-filter semantics as `withAuditRows()`.
     *
     * @param  list<list<string>>  $rows
     */
    public function withSafetyNetRows(array $rows): static
    {
        $this->auditService->withSafetyNetRows($rows);

        return $this;
    }

    public function daemon(): FakeDaemonManager
    {
        return $this->daemonManager;
    }

    public function clean(string $text, ?float $threshold = null): GazeSession
    {
        $this->cleanCalls[] = ['text' => $text, 'threshold' => $threshold];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->cleanHandler !== null) {
            // Always invoked with both arguments. PHP user-land closures
            // silently ignore surplus arguments, so pre-existing handlers
            // typed (string $text) keep working unchanged, while handlers
            // declaring (string $text, ?float $threshold) can branch on the
            // per-call threshold exactly like the real Gaze::clean() does.
            return ($this->cleanHandler)($text, $threshold);
        }

        return new GazeSession(
            cleanText: FakeTokenizer::mask($text),
            ciphertext: EncryptedBlob::wrap(base64_encode(json_encode(['text' => $text], JSON_THROW_ON_ERROR))),
            detections: 1,
        );
    }

    /**
     * Mirror the one-way mask() helper: record the call, then run the same
     * clean + token-map sweep composition the real Gaze::mask() performs.
     * It re-enters this fake's clean() — so a $cleanHandler returning
     * entries drives the masking just as the real binary's inventory would,
     * with no process invocation.
     *
     * @param  (callable(Entry): string)|null  $replace
     */
    public function mask(string $text, ?callable $replace = null): string
    {
        $this->maskCalls[] = ['text' => $text];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->maskHandler !== null) {
            return ($this->maskHandler)($text, $replace);
        }

        $session = $this->clean($text);

        $masked = $session->cleanText;
        foreach ($session->entries as $entry) {
            $label = $replace !== null ? $replace($entry) : '['.$entry->class.']';
            $masked = str_replace($entry->token, $label, $masked);
        }

        return $masked;
    }

    public function restore(GazeSession $session, string $text): string
    {
        $this->restoreCalls[] = ['text' => $text, 'clean_text' => $session->cleanText];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->restoreHandler !== null) {
            return ($this->restoreHandler)($session, $text);
        }

        /** @var array{text?: string}|null $map */
        $map = json_decode((string) base64_decode($session->ciphertext->decryptedBlob(), true), true);

        return is_array($map) ? (string) ($map['text'] ?? $text) : $text;
    }

    public function audit(?string $auditDbPath = null): FakeAuditService
    {
        return $this->auditService;
    }

    /**
     * @return list<array{text: string, threshold: float|null}>
     */
    public function cleanCalls(): array
    {
        return $this->cleanCalls;
    }

    /**
     * @return list<array{text: string}>
     */
    public function maskCalls(): array
    {
        return $this->maskCalls;
    }

    /**
     * @return list<array{text: string, clean_text: string}>
     */
    public function restoreCalls(): array
    {
        return $this->restoreCalls;
    }
}

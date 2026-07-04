<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Audit\AuditExportResult;
use CertaMesh\Gaze\Audit\AuditPurgeResult;
use CertaMesh\Gaze\Contracts\AuditService as AuditServiceContract;

/**
 * Test double for the audit verbs. Implements `Contracts\AuditService`
 * directly (no longer extends the concrete `Audit\AuditService`), so it
 * carries no inherited process-invoking state.
 */
final class FakeAuditService implements AuditServiceContract
{
    /** @var list<array{before: string, dry_run: bool}> */
    private array $purgeCalls = [];

    /** @var list<array{output: string|null, format: string, filters: array<string, string|true>}> */
    private array $exportCalls = [];

    /** @var list<array{filters: array<string, string>}> */
    private array $safetyNetQueryCalls = [];

    /**
     * @param  \Closure(string, bool): AuditPurgeResult|null  $purgeHandler
     * @param  \Closure(string|null, string): AuditExportResult|null  $exportHandler
     */
    public function __construct(
        private ?\Closure $purgeHandler = null,
        private ?\Closure $exportHandler = null,
    ) {}

    /**
     * Script the result of every purge execution. Usually configured via
     * `Gaze::fake()->auditPurgeUsing()`.
     *
     * @param  \Closure(string, bool): AuditPurgeResult  $handler
     */
    public function purgeUsing(\Closure $handler): static
    {
        $this->purgeHandler = $handler;

        return $this;
    }

    /**
     * Script the result of every export call. Usually configured via
     * `Gaze::fake()->auditExportUsing()`.
     *
     * @param  \Closure(string|null, string): AuditExportResult  $handler
     */
    public function exportUsing(\Closure $handler): static
    {
        $this->exportHandler = $handler;

        return $this;
    }

    public function purge(): FakePurgeBuilder
    {
        return new FakePurgeBuilder($this);
    }

    public function query(): FakeQueryBuilder
    {
        return new FakeQueryBuilder(audit: $this);
    }

    public function safetyNetQuery(): FakeSafetyNetQueryBuilder
    {
        return new FakeSafetyNetQueryBuilder(audit: $this);
    }

    public function recordPurgeCall(string $before, bool $dryRun): AuditPurgeResult
    {
        $this->purgeCalls[] = ['before' => $before, 'dry_run' => $dryRun];

        if ($this->purgeHandler !== null) {
            return ($this->purgeHandler)($before, $dryRun);
        }

        return new AuditPurgeResult(rawOutput: '', count: 0);
    }

    /**
     * @param  array<string, string|true>  $filters
     */
    public function recordExportCall(?string $output, string $format, array $filters): AuditExportResult
    {
        $this->exportCalls[] = ['output' => $output, 'format' => $format, 'filters' => $filters];

        if ($this->exportHandler !== null) {
            return ($this->exportHandler)($output, $format);
        }

        return new AuditExportResult(format: $format, path: $output, rawOutput: '');
    }

    /**
     * @return list<array{before: string, dry_run: bool}>
     */
    public function purgeCalls(): array
    {
        return $this->purgeCalls;
    }

    /**
     * @return list<array{output: string|null, format: string, filters: array<string, string|true>}>
     */
    public function exportCalls(): array
    {
        return $this->exportCalls;
    }

    /**
     * @param  array<string, string>  $filters
     */
    public function recordSafetyNetQueryCall(array $filters): void
    {
        $this->safetyNetQueryCalls[] = ['filters' => $filters];
    }

    /**
     * @return list<array{filters: array<string, string>}>
     */
    public function safetyNetQueryCalls(): array
    {
        return $this->safetyNetQueryCalls;
    }
}

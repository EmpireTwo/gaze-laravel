<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * Typed projection of the upstream gaze `leak_report` — the verification signal
 * the clean pipeline emits alongside the redacted text.
 *
 * Mirrors the upstream `LeakReportResponse` shape (gaze v0.12.0,
 * crates/gaze-cli/src/pipeline/run.rs). The adapter previously dropped this
 * field entirely, leaving callers to infer safety from the detection count —
 * which over-asserts, since a high count never proves a span did not bleed
 * through. This DTO surfaces the upstream coverage check so callers can show an
 * honest trust state instead of a falsely-reassuring green.
 *
 * METADATA ONLY: every field is a count, a backend label, or a hash. No source
 * text and no byte offsets are carried (see {@see LeakSuspect}).
 *
 * Stock-binary caveat: the `suspect_count` / `suspects` channel is populated by
 * the observer-only Pass-3 safety net, which is a compile-time feature absent
 * from the stock release binary — so through the stock CLI those stay 0/empty
 * and the strongest reachable state is Unverified. The four coverage-gap counts
 * come from the core pipeline and are always present. This mirrors the
 * restore-telemetry caveat: the surface ships forward-compatible, lighting up
 * Suspect (red) only when an adopter runs a safety-net-enabled binary.
 *
 * @see CoverageState for the resolved trust state.
 * @see https://github.com/CertaMesh/gaze
 */
final readonly class LeakReport
{
    /**
     * @param  list<LeakSuspect>  $suspects
     */
    public function __construct(
        public int $suspectCount,
        public int $uncoveredCount,
        public int $partialBleedCount,
        public int $classMismatchCount,
        public int $localeSkippedCount,
        public array $suspects = [],
        public ?string $replayHash = null,
    ) {}

    /**
     * Build a LeakReport from the decoded `leak_report` object. Tolerates absent
     * or malformed fields (defaults to zero counts / empty suspects) so a shape
     * drift never turns a clean() into a hard failure.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $stats = isset($payload['stats']) && is_array($payload['stats']) ? $payload['stats'] : [];

        $replayHash = isset($payload['replay_hash']) && is_string($payload['replay_hash'])
            ? $payload['replay_hash']
            : null;

        return new self(
            suspectCount: self::count($stats, 'suspect_count'),
            uncoveredCount: self::count($stats, 'uncovered_count'),
            partialBleedCount: self::count($stats, 'partial_bleed_count'),
            classMismatchCount: self::count($stats, 'class_mismatch_count'),
            localeSkippedCount: self::count($stats, 'locale_skipped_count'),
            suspects: self::mapSuspects($payload['suspects'] ?? null),
            replayHash: $replayHash,
        );
    }

    /**
     * Resolve the trust state. Suspect (red) wins over Unverified (amber) when
     * both an active suspect and coverage gaps are present — the harder signal
     * dominates. Verified (green) requires both no suspects AND no gaps.
     */
    public function coverageState(): CoverageState
    {
        if ($this->hasSuspectedLeak()) {
            return CoverageState::Suspect;
        }

        if ($this->hasCoverageGap()) {
            return CoverageState::Unverified;
        }

        return CoverageState::Verified;
    }

    /**
     * Whether the safety net actively flagged a span that may still carry raw
     * PII. Distinct from coverage gaps, which are weaker "could not fully verify"
     * signals rather than an active leak flag.
     */
    public function hasSuspectedLeak(): bool
    {
        return $this->suspectCount > 0;
    }

    /**
     * Whether upstream reported any partial-coverage signal: an uncovered span,
     * a partial bleed, a class mismatch, or a locale-skipped field.
     */
    public function hasCoverageGap(): bool
    {
        return $this->uncoveredCount > 0
            || $this->partialBleedCount > 0
            || $this->classMismatchCount > 0
            || $this->localeSkippedCount > 0;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private static function count(array $stats, string $key): int
    {
        return isset($stats[$key]) && is_numeric($stats[$key]) ? (int) $stats[$key] : 0;
    }

    /**
     * @return list<LeakSuspect>
     */
    private static function mapSuspects(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $suspects = [];
        foreach ($raw as $item) {
            if (is_array($item)) {
                $suspects[] = LeakSuspect::fromArray($item);
            }
        }

        return $suspects;
    }
}

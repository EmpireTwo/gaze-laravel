<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

final readonly class GazeSession
{
    /**
     * The session is the flattened projection of the upstream clean response:
     * `stats.detections`, `stats.locale_chain`, `stats.dictionaries_loaded` and
     * `stats.context_source` each land on exactly one property here, so there is
     * never a second way to read the same value.
     *
     * @param  list<Entry>  $entries
     * @param  ?LeakReport  $leakReport  Upstream verification signal, or null when
     *                                   the binary did not emit a leak_report.
     * @param  list<string>  $localeChain  Resolved locale fallback chain, priority
     *                                     ordered, as the binary actually applied
     *                                     it — the answer to "did my `GAZE_LOCALE`
     *                                     reach detection?". Empty when the binary
     *                                     emitted no chain.
     * @param  list<LoadedDictionary>  $dictionariesLoaded  Dictionaries the pipeline
     *                                                      loaded for this call (name,
     *                                                      term count, provenance).
     * @param  ?string  $contextSource  Provenance of the typed Context envelope
     *                                  (`'cli'` upstream). Always null through this
     *                                  package: `--context-json` is a documented
     *                                  deferral, so nothing forwards a context.
     */
    public function __construct(
        public string $cleanText,
        public EncryptedBlob $ciphertext,
        public int $detections,
        public array $entries = [],
        public ?LeakReport $leakReport = null,
        public array $localeChain = [],
        public array $dictionariesLoaded = [],
        public ?string $contextSource = null,
    ) {}

    /**
     * Trust state of this clean result, derived from the upstream leak_report.
     *
     * A null leak_report degrades to Unverified — never Verified. The detection
     * count alone cannot back a green claim, so without the upstream coverage
     * check we report amber rather than over-assert safety. This is the whole
     * point of the surface: a count is not a verification.
     */
    public function coverageState(): CoverageState
    {
        return $this->leakReport?->coverageState() ?? CoverageState::Unverified;
    }

    /**
     * The primary (highest priority) locale the binary resolved for this call,
     * or null when it emitted no chain. The rest of the chain stays available
     * on {@see self::$localeChain}; this is the head of that same list, not a
     * second source of truth.
     */
    public function activeLocale(): ?string
    {
        return $this->localeChain[0] ?? null;
    }

    /**
     * Whether the upstream safety net actively flagged a span that may still
     * carry raw PII. False when no leak_report was emitted (nothing flagged).
     */
    public function hasSuspectedLeak(): bool
    {
        return $this->leakReport?->hasSuspectedLeak() ?? false;
    }
}

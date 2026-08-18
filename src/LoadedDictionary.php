<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * One dictionary the upstream pipeline loaded for this clean call.
 *
 * Mirrors the upstream `LoadedDictionaryStats` shape (gaze v0.12.0,
 * crates/gaze-cli/src/pipeline/run.rs) carried inside `stats.dictionaries_loaded`.
 *
 * METADATA ONLY: the name, the term count, and the provenance label. No terms
 * and no source text cross this surface — a dictionary's entries are exactly
 * the values an adopter is redacting, so they stay inside the binary.
 *
 * @see GazeSession::$dictionariesLoaded
 * @see https://github.com/CertaMesh/gaze
 */
final readonly class LoadedDictionary
{
    public function __construct(
        public string $name,
        public int $termCount,
        public string $source,
    ) {}

    /**
     * Build a LoadedDictionary from one decoded `dictionaries_loaded[]` object.
     *
     * Missing or wrongly-typed fields degrade to `''` / `0` rather than
     * throwing: a stats shape drift must never turn a successful clean() into
     * a hard failure. Unknown fields are ignored for forward compatibility.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : '',
            termCount: is_numeric($payload['term_count'] ?? null) ? (int) $payload['term_count'] : 0,
            source: is_string($payload['source'] ?? null) ? $payload['source'] : '',
        );
    }
}

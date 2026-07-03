<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * One suspected leak reported by the upstream observer-only safety net.
 *
 * Mirrors the upstream `LeakSuspectResponse` shape (gaze v0.11.3,
 * crates/gaze-cli/src/pipeline/run.rs). Every field here is METADATA — by
 * upstream design the leak report never serialises source text or byte offsets:
 * `rawLabel` is the backend's own category label ("private_person"), never the
 * matched value, and only `spanLen` survives (start/end offsets are dropped so
 * the value cannot be located against the clean text).
 *
 * `fromArray()` reads a strict allowlist of those metadata fields. Any other
 * key on the decoded element — including a future or tampered field that smuggled
 * raw text — is ignored rather than carried, so this DTO can never become a PII
 * sink even if the upstream schema drifts.
 *
 * @see https://github.com/CertaMesh/gaze
 */
final readonly class LeakSuspect
{
    public function __construct(
        public string $safetyNetId,
        public string $rawLabel,
        public string $mappedClass,
        public string $leakKind,
        public ?string $pipelineClass,
        public int $spanLen,
        public ?string $fieldPath,
        public ?float $score,
    ) {}

    /**
     * Build a LeakSuspect from a decoded suspect element, reading only the
     * metadata allowlist. Missing fields default to safe empties; unknown keys
     * are dropped. Never throws on shape drift.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            safetyNetId: self::str($payload, 'safety_net_id'),
            rawLabel: self::str($payload, 'raw_label'),
            mappedClass: self::str($payload, 'mapped_class'),
            leakKind: self::str($payload, 'leak_kind'),
            pipelineClass: self::nullableStr($payload, 'pipeline_class'),
            spanLen: isset($payload['span_len']) && is_numeric($payload['span_len'])
                ? (int) $payload['span_len']
                : 0,
            fieldPath: self::nullableStr($payload, 'field_path'),
            score: isset($payload['score']) && is_numeric($payload['score'])
                ? (float) $payload['score']
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function str(array $payload, string $key): string
    {
        return isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableStr(array $payload, string $key): ?string
    {
        return isset($payload[$key]) && is_string($payload[$key]) ? $payload[$key] : null;
    }
}

<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

/**
 * Immutable option bag for the one-shot `gaze` CLI runtime.
 *
 * Every tuning knob the `Gaze` service forwards to the binary lives here;
 * the `Gaze` constructor takes exactly one of these instead of ~25 scalar
 * parameters. `fromConfig()` is the single coercion layer between the
 * `config/gaze.php` array (env-sourced strings, user overrides) and the
 * typed values the service consumes — the config file itself ships raw
 * `env()` reads without casts.
 *
 * `fromConfig()` accepts both config shapes:
 *
 *  - the nested `'safety_net' => ['enabled' => …, 'mode' => …, …]` group
 *    shipped since v0.13, and
 *  - the flat root keys (`safety_net_mode`, `openai_filter_command`, …)
 *    published by pre-v0.13 configs (deprecated, still supported — see
 *    UPGRADING.md).
 *
 * When both spellings carry a value, the nested key wins.
 */
final readonly class GazeOptions
{
    /**
     * @param  list<string>|null  $rulepacks
     * @param  list<string>|null  $rulepackPaths
     */
    public function __construct(
        public int $timeoutSeconds = 30,
        public ?int $maxBytes = null,
        public ?int $sessionTtlSeconds = null,
        public ?string $auditDbPath = null,
        public ?string $locale = null,
        public ?array $rulepacks = null,
        public ?array $rulepackPaths = null,
        public bool $safetyNet = false,
        public ?string $safetyNetDevice = null,
        public ?string $openaiFilterCommand = null,
        public ?string $openaiFilterCheckpoint = null,
        public ?string $openaiFilterOperatingPoint = null,
        public ?int $safetyNetTimeoutMs = null,
        public ?int $safetyNetInputLimitBytes = null,
        public ?string $safetyNetMode = null,
        public ?string $safetyNetBackend = null,
        public ?string $kijiBackend = null,
        public ?string $kijiDistilbertPrecision = null,
        public ?string $kijiDistilbertCommand = null,
        public ?string $kijiDistilbertModelDir = null,
        public ?string $safetyNetFallback = null,
        public ?string $sessionScope = null,
        public ?string $restoreMode = null,
        public bool $restoreTelemetry = false,
        public ?float $nerThreshold = null,
    ) {}

    /**
     * Build options from the `config('gaze')` array.
     *
     * This is the ONE place raw config values are coerced: env vars arrive
     * as strings (`"7500"`, `"true"`, `""`), user overrides may already be
     * typed, and unset keys are null — every branch normalizes through the
     * `*OrNull` helpers so empty strings mean "omit the flag" exactly like
     * null does.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        // The `safety_net` root key is polymorphic across config vintages:
        // pre-v0.13 published configs carry the flat bool enable-switch,
        // v0.13+ ships the nested group with an `enabled` key. Nested keys
        // win over their deprecated flat counterparts.
        $safetyNetRoot = $config['safety_net'] ?? null;
        $group = is_array($safetyNetRoot) ? $safetyNetRoot : [];
        $opf = is_array($group['openai_filter'] ?? null) ? $group['openai_filter'] : [];
        $kiji = is_array($group['kiji'] ?? null) ? $group['kiji'] : [];
        $enabled = is_array($safetyNetRoot) ? ($group['enabled'] ?? false) : $safetyNetRoot;

        return new self(
            timeoutSeconds: self::intOrNull($config['timeout_seconds'] ?? null) ?? 30,
            maxBytes: self::intOrNull($config['max_bytes'] ?? null),
            sessionTtlSeconds: self::intOrNull($config['session_ttl_seconds'] ?? null),
            auditDbPath: self::stringOrNull($config['audit_db_path'] ?? null),
            locale: self::stringOrNull($config['locale'] ?? null),
            rulepacks: self::stringListOrNull($config['rulepacks'] ?? null),
            rulepackPaths: self::stringListOrNull($config['rulepack_paths'] ?? null),
            safetyNet: (bool) $enabled,
            safetyNetDevice: self::stringOrNull($group['device'] ?? $config['safety_net_device'] ?? null),
            openaiFilterCommand: self::stringOrNull($opf['command'] ?? $config['openai_filter_command'] ?? null),
            openaiFilterCheckpoint: self::stringOrNull($opf['checkpoint'] ?? $config['openai_filter_checkpoint'] ?? null),
            openaiFilterOperatingPoint: self::stringOrNull($opf['operating_point'] ?? $config['openai_filter_operating_point'] ?? null),
            safetyNetTimeoutMs: self::intOrNull($group['timeout_ms'] ?? $config['safety_net_timeout_ms'] ?? null),
            safetyNetInputLimitBytes: self::intOrNull($group['input_limit_bytes'] ?? $config['safety_net_input_limit_bytes'] ?? null),
            safetyNetMode: self::stringOrNull($group['mode'] ?? $config['safety_net_mode'] ?? null),
            safetyNetBackend: self::stringOrNull($group['backend'] ?? $config['safety_net_backend'] ?? null),
            kijiBackend: self::stringOrNull($kiji['backend'] ?? $config['kiji_backend'] ?? null),
            kijiDistilbertPrecision: self::stringOrNull($kiji['distilbert_precision'] ?? $config['kiji_distilbert_precision'] ?? null),
            kijiDistilbertCommand: self::stringOrNull($kiji['distilbert_command'] ?? $config['kiji_distilbert_command'] ?? null),
            kijiDistilbertModelDir: self::stringOrNull($kiji['distilbert_model_dir'] ?? $config['kiji_distilbert_model_dir'] ?? null),
            safetyNetFallback: self::stringOrNull($group['fallback'] ?? $config['safety_net_fallback'] ?? null),
            sessionScope: self::stringOrNull($config['session_scope'] ?? null),
            restoreMode: self::stringOrNull($config['restore_mode'] ?? null),
            restoreTelemetry: (bool) ($config['restore_telemetry'] ?? false),
            nerThreshold: self::floatOrNull($config['ner_threshold'] ?? null),
        );
    }

    /**
     * Non-empty string or null. Empty string means "key present but unset"
     * (the natural shape of an empty env var) and normalizes to null so the
     * flag is omitted.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Normalize a config value to a non-empty list of strings, or null when
     * unset/empty. Non-string entries are dropped — they were already outside
     * the declared list<string> contract and would only corrupt the CLI
     * arguments built from them.
     *
     * @return non-empty-list<string>|null
     */
    private static function stringListOrNull(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $strings === [] ? null : $strings;
    }
}

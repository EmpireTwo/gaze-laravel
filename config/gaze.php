<?php

declare(strict_types=1);

return [
    /*
     * Absolute path or executable name for the gaze binary.
     *
     * Resolution contract (BinaryResolver):
     *   null / unset → auto-discover: prefer vendor/bin/gaze (auto-installed by
     *                  the Composer plugin), then fall back to the first "gaze"
     *                  on $PATH.
     *   non-empty    → used as-is (treated as explicit override). Set to an
     *                  absolute path for production; a bare name like "gaze"
     *                  defeats the vendor/bin fallback and is discouraged.
     */
    'binary' => env('GAZE_BINARY'),

    /*
     * Hard ceiling on any single gaze invocation. A hung process must be
     * killed rather than tying up a worker. Raw env read —
     * GazeOptions::fromConfig coerces to int (default 30).
     */
    'timeout_seconds' => env('GAZE_TIMEOUT', 30),

    /*
     * Path to the detector policy file passed to `gaze clean`.
     */
    'policy_path' => env('GAZE_POLICY_PATH', base_path('policy.toml')),

    /*
     * Optional explicit max-bytes override for the CLI. When unset, the
     * library pre-flight still enforces the v0.3 default ceiling of 10 MB.
     */
    'max_bytes' => env('GAZE_MAX_BYTES'),

    /*
     * Optional session TTL forwarded to the CLI.
     */
    'session_ttl_seconds' => env('GAZE_SESSION_TTL'),

    /*
     * Optional session isolation scope forwarded to `gaze clean`.
     * Valid values are `ephemeral`, `conversation`, and `persistent`.
     * Null omits the flag and lets upstream apply its default.
     */
    'session_scope' => env('GAZE_SESSION_SCOPE'),

    /*
     * Optional dedicated base64-encoded 32-byte key for session-blob encryption.
     * When unset, EncryptedBlob falls back to Laravel's default Crypt facade
     * (keyed on APP_KEY). When set, the key MUST be valid or boot fails loudly.
     */
    'blob_encryption_key' => env('GAZE_ENCRYPTION_KEY'),

    /*
     * Optional SQLite redaction-log database path.
     *
     * When set:
     *   - `Gaze::clean()` forwards `--audit-db=<path>` so redaction events are
     *     written to this DB.
     *   - `Gaze::audit()->purge()` (and future query/export verbs) read from
     *     this DB.
     *
     * When null, audit verbs throw GazeAuditDbNotConfiguredException at call
     * time (never at boot). `Gaze::clean()` continues to work without audit.
     *
     * Per-call overrides ARE supported: `Gaze::audit($path)->purge()...` wins
     * over this config value. The resolved value is passed verbatim to the
     * binary which creates the file on first write (no Laravel-side
     * file_exists pre-flight).
     */
    'audit_db_path' => env('GAZE_AUDIT_DB_PATH'),

    /*
     * Locale hint forwarded to `gaze clean` as `--locale=<value>`. The value
     * is passed verbatim, and upstream parses it as a comma-separated,
     * priority-ordered BCP47 fallback chain — so both a single locale
     * (`GAZE_LOCALE=de`) and a chain (`GAZE_LOCALE=de-DE,en`) work; earlier
     * entries win. Null passes no flag.
     */
    'locale' => env('GAZE_LOCALE'),

    /*
     * Optional global override for the policy `[ner]` detection threshold,
     * forwarded to `gaze clean` as `--ner-threshold=<value>`. Must be between
     * 0.0 and 1.0 inclusive. Null omits the flag and lets upstream apply the
     * policy's own threshold. A per-call `Gaze::clean($text, $threshold)`
     * argument wins over this config value.
     */
    'ner_threshold' => env('GAZE_NER_THRESHOLD'),

    /*
     * Comma-separated list of bundled rulepack names forwarded as `--rulepack-bundled=`
     * flags (e.g. `GAZE_RULEPACKS=names,emails`).
     */
    'rulepacks' => array_filter(explode(',', env('GAZE_RULEPACKS', ''))),

    /*
     * Comma-separated list of filesystem paths to custom rulepack TOML files,
     * forwarded as `--rulepack-path=` flags
     * (e.g. `GAZE_RULEPACK_PATHS=/path/a.toml,/path/b.toml`).
     */
    'rulepack_paths' => array_filter(explode(',', env('GAZE_RULEPACK_PATHS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Safety Net
    |--------------------------------------------------------------------------
    |
    | Nested configuration for the safety-net classifier tier (v0.13+ shape).
    | Values are raw `env()` reads — `GazeOptions::fromConfig()` is the single
    | coercion layer, so no casts belong in this file. Env var names are
    | unchanged from the pre-v0.13 flat keys.
    |
    | The pre-v0.13 flat root keys (`safety_net_mode`,
    | `openai_filter_command`, `kiji_backend`, …) are DEPRECATED but still
    | read as a fallback, so previously published configs keep working. At
    | registration the provider also back-fills the flat keys from this group
    | (and collapses `gaze.safety_net` to the bool enable switch) so legacy
    | `config('gaze.safety_net_*')` readers observe the same values. See
    | UPGRADING.md for the key map.
    */
    'safety_net' => [
        /*
         * Enable the safety-net classifier. When true, the binary passes
         * `--safety-net=openai-filter` (v0.6.4+ contract).
         */
        'enabled' => env('GAZE_SAFETY_NET', false),

        /*
         * Optional explicit safety-net backend selector. Valid values are
         * `openai-filter` (Tier 2 OpenAI privacy-filter subprocess) and
         * `kiji-distilbert` (Tier 2.5 DistilBERT NER backend). Forwarded
         * as `--safety-net-backend=<value>` which wins over the legacy
         * `--safety-net=<kind>` flag when both are set. Null omits the flag and
         * lets upstream keep the v0.6/v0.7 single-backend default of
         * `openai-filter`.
         */
        'backend' => env('GAZE_SAFETY_NET_BACKEND'),

        /*
         * CUDA/CPU device for the safety-net model (e.g. `cuda:0`, `cpu`).
         * Forwarded as `--openai-filter-device=<value>`. Null omits the flag.
         */
        'device' => env('GAZE_SAFETY_NET_DEVICE'),

        /*
         * Optional safety-net subprocess timeout in milliseconds. Must be
         * positive. Forwarded as `--safety-net-timeout-ms=<value>`. Null lets
         * the binary use its default of 5000 ms.
         */
        'timeout_ms' => env('GAZE_SAFETY_NET_TIMEOUT_MS'),

        /*
         * Optional clean-text size cap, in bytes, for the safety-net
         * subprocess. Must be positive. Forwarded as
         * `--safety-net-input-limit-bytes=<value>`. Null lets the binary use
         * its default of 1048576 bytes.
         */
        'input_limit_bytes' => env('GAZE_SAFETY_NET_INPUT_LIMIT_BYTES'),

        /*
         * Optional suspected-leak handling mode. Valid values are `strict`,
         * `tolerant`, `redact`, and `resolve`. Forwarded as
         * `--safety-net-mode=<value>`. Null lets the binary apply its default,
         * which flipped from `strict` to `resolve` in upstream v0.8.1. Adopters
         * who relied on the legacy strict-as-default behaviour must set
         * `GAZE_SAFETY_NET_MODE=strict` explicitly. `tolerant` emits a
         * deprecation warning upstream.
         */
        'mode' => env('GAZE_SAFETY_NET_MODE'),

        /*
         * Optional fallback when `mode` is `redact` or `resolve` and the
         * active backend cannot complete (timeout, oversized input, etc.).
         * Valid values are `strict`, `tolerant`, and `redact`. Forwarded as
         * `--safety-net-fallback=<value>`. Null lets the binary use its
         * default of `redact`.
         */
        'fallback' => env('GAZE_SAFETY_NET_FALLBACK'),

        /*
         * Tier 2 OpenAI privacy-filter (`opf`) subprocess backend.
         */
        'openai_filter' => [
            /*
             * Optional path to the local `opf` binary used by the safety-net
             * classifier. Forwarded as `--openai-filter-command=<value>`.
             * Null lets the binary use PATH lookup.
             */
            'command' => env('GAZE_OPENAI_FILTER_COMMAND'),

            /*
             * Optional model checkpoint directory for the safety-net
             * classifier. Forwarded as `--openai-filter-checkpoint=<value>`.
             * Null lets the binary use its built-in default.
             */
            'checkpoint' => env('GAZE_OPENAI_FILTER_CHECKPOINT'),

            /*
             * Optional safety-net sensitivity trade-off. Valid values are
             * `high-recall`, `balanced`, and `high-precision`. Forwarded as
             * `--openai-filter-operating-point=<value>`. Null lets the binary
             * use its default.
             */
            'operating_point' => env('GAZE_OPENAI_FILTER_OPERATING_POINT'),
        ],

        /*
         * Tier 2.5 Kiji DistilBERT NER backend.
         */
        'kiji' => [
            /*
             * Optional Kiji DistilBERT runtime backend. Valid values in the
             * upstream GitHub-release binary are `subprocess` and `ort`;
             * feature builds may add `tract` or `candle`. Forwarded as
             * `--kiji-backend=<value>`. For v0.9 int8 inference, set
             * `GAZE_KIJI_BACKEND=ort`.
             */
            'backend' => env('GAZE_KIJI_BACKEND'),

            /*
             * Optional Kiji DistilBERT ONNX precision. Valid values are
             * `fp32` and `int8`. Forwarded as
             * `--kiji-distilbert-precision=<value>`. Upstream requires
             * `--kiji-backend=ort` when this is `int8`.
             */
            'distilbert_precision' => env('GAZE_KIJI_DISTILBERT_PRECISION'),

            /*
             * Optional path to the local Kiji DistilBERT subprocess binary
             * used when `backend=kiji-distilbert`. Forwarded as
             * `--kiji-distilbert-command=<value>`. Null lets the binary use
             * its PATH lookup.
             */
            'distilbert_command' => env('GAZE_KIJI_DISTILBERT_COMMAND'),

            /*
             * Optional pinned-artifact directory for the Kiji DistilBERT
             * backend. The directory must carry `SHA256SUMS`, `labels.json`,
             * `model.onnx`, and `tokenizer.json` (0o700 dir + 0o600 files for
             * the fail-closed Axis-1 guard). Forwarded as
             * `--kiji-distilbert-model-dir=<value>`. Null omits the flag and
             * lets upstream surface its own typed `SafetyNetArtifactMissing`
             * envelope on first invocation.
             */
            'distilbert_model_dir' => env('GAZE_KIJI_DISTILBERT_MODEL_DIR'),
        ],
    ],

    /*
     * Optional restore behavior for unknown tokens. Valid values are `strict`
     * and `tolerant`. Null omits the flag and lets upstream default to `strict`.
     */
    'restore_mode' => env('GAZE_RESTORE_MODE'),

    /*
     * Enable restore-decision telemetry. When truthy, `Gaze::restore()` forwards
     * `--telemetry` (and `--audit-db=<gaze.audit_db_path>` when that path is set)
     * so the binary records restore-decision / unknown-token audit rows. Null or
     * false = upstream default (telemetry off); this surface adds no detection
     * logic — it only forwards the upstream flag.
     *
     * CAVEAT: two of the upstream audit columns — restore_fresh_pii_count and
     * restore_manifest_bypass_count — are ALWAYS 0 through the stock gaze CLI,
     * because gaze-cli's run_restore never enables the Phase-B DLP builder. This
     * surface ships for restore-decision and unknown-token audit trails, NOT for
     * outbound-DLP fresh-PII detection. Do not rely on it for DLP.
     */
    'restore_telemetry' => env('GAZE_RESTORE_TELEMETRY'),

    /*
     * gaze-proxy daemon settings.
     *
     * Wraps the upstream `gaze proxy *` subcommands. Each key forwards as an
     * exact `--flag` to the binary; null/empty omits the flag and lets the
     * binary fall back to its own config file (default `~/.config/gaze/proxy.toml`).
     *
     * The upstream `proxy` subcommand is feature-gated. The GitHub-release
     * binary asset is built WITHOUT `--features proxy`. Adopters that want
     * `php artisan gaze:proxy:*` at runtime must rebuild upstream with:
     *
     *     cargo install gaze-cli --features proxy
     *
     * See `docs/proxy.md` for the full reference.
     */
    'proxy' => [
        /*
         * Loopback bind address. Format: `host:port`. Forwarded as `--bind=`.
         */
        'bind' => env('GAZE_PROXY_BIND', '127.0.0.1:8787'),

        /*
         * Session TTL applied to redaction-session state held by the daemon.
         * Duration string (`30m`, `1h`, `120s`). Forwarded as `--session-ttl=`.
         */
        'session_ttl' => env('GAZE_PROXY_SESSION_TTL', '30m'),

        /*
         * Bundled rulepack name passed to the proxy pipeline. Forwarded as
         * `--rulepack=`. Use `core` (default) unless you publish a custom
         * bundle.
         */
        'rulepack' => env('GAZE_PROXY_RULEPACK', 'core'),

        /*
         * Optional policy.toml path forwarded as `--policy=`. Null lets the
         * binary fall back to its default pipeline (no policy file).
         */
        'policy_path' => env('GAZE_PROXY_POLICY_PATH'),

        /*
         * Upstream provider URLs forwarded as `--upstream-openai=`,
         * `--upstream-anthropic=`, `--upstream-gemini=`. Each key takes a full
         * https:// URL. Adapter ships the canonical defaults so a fresh
         * `php artisan gaze:proxy:start` works without further config.
         */
        'upstream' => [
            'openai' => env('GAZE_PROXY_UPSTREAM_OPENAI', 'https://api.openai.com/'),
            'anthropic' => env('GAZE_PROXY_UPSTREAM_ANTHROPIC', 'https://api.anthropic.com/'),
            'gemini' => env('GAZE_PROXY_UPSTREAM_GEMINI', 'https://generativelanguage.googleapis.com/'),
        ],

        /*
         * Graceful-shutdown timeout for `gaze:proxy:stop` / `:restart`.
         * Duration string (`10s`, `30s`, `1m`). Forwarded as `--timeout=`.
         */
        'stop_timeout' => env('GAZE_PROXY_STOP_TIMEOUT', '10s'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Daemon
    |--------------------------------------------------------------------------
    |
    | Flat configuration for the long-lived `gaze daemon` JSONL stdio runtime.
    | All keys default to null so the upstream binary applies its own
    | defaults; populating a key forwards the value as the matching flag.
    |
    | `gaze:daemon:serve` also forwards the shared pipeline flags —
    | `--locale`, `--ner-threshold`, and the full safety-net / OPF / Kiji
    | family — sourced from the SAME top-level `gaze.*` keys the one-shot
    | `Gaze::clean()` path uses, so a configured pipeline behaves
    | identically in both runtimes. Only daemon-specific knobs live here.
    |
    | The upstream `daemon` subcommand may be feature-gated. When the binary
    | lacks the feature, `php artisan gaze:daemon:serve` will fail at first
    | invocation. Doctor's `--deep` probe pre-flights this and surfaces:
    |
    |     cargo install gaze-cli --features daemon
    |
    | See `docs/daemon.md` for the full reference.
    |
    | Connections-style configuration (`gaze.daemon.connections.{name}.*`) is
    | intentionally not shipped — it's an additive MINOR promotion once a
    | second adopter files for multi-daemon orchestration.
    */
    'daemon' => [
        /*
         * Policy TOML path forwarded as `--policy=`. Null skips the flag and
         * lets the binary fall back to its default pipeline (no policy).
         *
         * Doctor's daemon section is skipped when this key is null — that is
         * the opt-in signal that the adopter intends to use daemon mode.
         */
        'policy_path' => env('GAZE_DAEMON_POLICY_PATH'),

        /*
         * Audit DB path forwarded as `--audit-db=`. Daemon-emitted rows
         * stamp `provenance_stage = "daemon"`. Null leaves audit disabled.
         */
        'audit_db_path' => env('GAZE_DAEMON_AUDIT_DB_PATH'),

        /*
         * Per-request timeout the adapter applies to each JSONL round-trip.
         * Integer milliseconds. Default 5000ms. Cold first request may want
         * a higher value when the upstream pipeline includes Kiji ORT init.
         *
         * NOTE: this is an adapter-side ceiling, not an upstream flag.
         */
        'request_timeout_ms' => env('GAZE_DAEMON_REQUEST_TIMEOUT_MS', 5000),

        /*
         * Daemon idle timeout forwarded as `--idle-timeout=`. Integer
         * seconds. Null lets the binary apply its default.
         */
        'idle_timeout_s' => env('GAZE_DAEMON_IDLE_TIMEOUT_S'),

        /*
         * Per-session idle eviction window forwarded as
         * `--session-idle-timeout=`. Integer seconds. Null lets the binary
         * apply its default (3600 s in v0.11.x). Evicted sessions write an
         * audit row with `source = "daemon.session_eviction"`.
         */
        'session_idle_timeout_s' => env('GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S'),

        /*
         * Maximum live sessions before LRU eviction, forwarded as
         * `--session-cap=`. Null lets the binary apply its default
         * (1000 in v0.11.x).
         */
        'session_cap' => env('GAZE_DAEMON_SESSION_CAP'),

        /*
         * Optional policy `[ner].model_dir` override forwarded as
         * `--ner-model-dir=`. Null omits the flag and keeps the policy's
         * own model directory.
         */
        'ner_model_dir' => env('GAZE_DAEMON_NER_MODEL_DIR'),

        /*
         * Optional policy `[ner].locale` override forwarded as
         * `--ner-locale=`. Null omits the flag and keeps the policy's
         * own NER locale.
         */
        'ner_locale' => env('GAZE_DAEMON_NER_LOCALE'),

        /*
         * Optional comma-separated locale list for the Kiji DistilBERT
         * safety-net backend, forwarded as `--kiji-distilbert-locales=`.
         * Daemon-scoped because the one-shot path exposes no top-level
         * equivalent. Null omits the flag.
         */
        'kiji_distilbert_locales' => env('GAZE_DAEMON_KIJI_DISTILBERT_LOCALES'),

        /*
         * Override path for the `gaze` binary used by `gaze:daemon:serve`.
         * Falls back to `BinaryResolver` resolution when null.
         */
        'binary_path' => env('GAZE_DAEMON_BINARY_PATH'),

        /*
         * Optional file path the daemon stderr is appended to when invoked
         * via `gaze:daemon:serve`. Null leaves stderr inherited from the
         * supervisor (systemd / Horizon / supervisord). Spec mandates stderr
         * is the log surface — no `--log-file` flag exists upstream.
         */
        'stderr_path' => env('GAZE_DAEMON_STDERR_PATH'),
    ],
];

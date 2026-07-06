# Upstream Coverage

Living parity checklist for upstream `CertaMesh/gaze` v0.11.3.

> Adopter usage: [docs/safety-net.md](../how-to/safety-net.md). Why surfaces land here vs. defer: [docs/NORTH_STAR.md](../NORTH_STAR.md) (surface promotion rule). GDPR posture for these surfaces (pseudonymization, storage limitation, erasure): [docs/explanation/gdpr.md](../explanation/gdpr.md) — adopter guidance, not legal advice.

## Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze clean` | `CertaMesh\Gaze\Gaze::clean()` |
| `gaze clean` (one-way output reshape) | `CertaMesh\Gaze\Gaze::mask()` — redacts the clean inventory into masked labels (`[Class]` default, or a `callable(Entry): string`). NON-reversible: no session blob, no `restore()` counterpart. Adds no detection — reshapes `clean()`'s tokens only. |
| `gaze restore` | `CertaMesh\Gaze\Gaze::restore()` |
| `gaze audit query` | `Gaze::audit()->query()` — fluent builder covering all 11 upstream filter flags (see [Audit query/export filters](#audit-queryexport-filters-v011x)) |
| `gaze audit export` | `Gaze::audit()->query()->…->export(?string $output, string $format = 'jsonl')` — reuses the query builder's filter state (upstream applies the identical filter flags to both subcommands) |
| `gaze audit purge` | `Gaze::audit()->purge()` + `php artisan gaze:audit:purge` (scheduler-friendly; `--before` ISO 8601/relative, `--audit-db`, `--dry-run`, `--force`) |
| `gaze audit safety-net query` | `Gaze::audit()->safetyNetQuery()` — flattened name; `query` is upstream's only `safety-net` subcommand |

## Clean Flags

| Upstream flag | Laravel surface |
|---|---|
| `--policy` | `gaze.policy_path` / `GAZE_POLICY_PATH` |
| `--format=json` | Always set by `Gaze::clean()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--session-ttl` | `gaze.session_ttl_seconds` / `GAZE_SESSION_TTL` |
| `--session-scope` | `gaze.session_scope` / `GAZE_SESSION_SCOPE` |
| `--audit-db` | `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH` |
| `--locale` | `gaze.locale` / `GAZE_LOCALE` — passed verbatim. Upstream accepts a **comma-separated, priority-ordered fallback chain** (`--help`: "Active locale fallback chain, comma separated and priority ordered"), so `GAZE_LOCALE=de-DE,en` works today; a single BCP47 value is just a chain of one. |
| `--ner-model-dir` (runtime) | **Not exposed.** Runtime override of policy `[ner].model_dir` on `gaze clean`. Deferred — the adapter only sets `model_dir` at install time via `gaze:install:ner` writing `policy.toml`. See [Deferred](#deferred). |
| `--ner-locale` (runtime) | **Not exposed.** Runtime override of policy `[ner].locale` on `gaze clean`. Deferred — install-time only via `gaze:install:ner --locale`. See [Deferred](#deferred). |
| `--ner-threshold` | per-call `Gaze::clean($text, $threshold)` arg + `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` (override policy `[ner]` threshold, 0.0–1.0 inclusive; per-call wins over config; null = upstream policy default) |
| `--rulepack-bundled` | `gaze.rulepacks` / `GAZE_RULEPACKS` |
| `--rulepack-path` | `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` |
| `--safety-net` | `gaze.safety_net` / `GAZE_SAFETY_NET` |
| `--safety-net-backend` | `gaze.safety_net_backend` / `GAZE_SAFETY_NET_BACKEND` (v0.8.x; `openai-filter` \| `kiji-distilbert`) |
| `--safety-net-registry` | **Not exposed.** v0.9.0 locale-aware Pass-3 registry dispatch (boolean). Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--safety-net-add` | **Not exposed.** Repeatable registry backend add (`openai-filter` \| `kiji-distilbert`). Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--opf-locales` | **Not exposed.** Locale list for the OPF registry entry. Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--kiji-distilbert-locales` | **Not exposed.** Locale list for the Kiji DistilBERT registry entry. Deferred — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090). |
| `--opf-command` / `--opf-checkpoint` | **No surface needed** — upstream `--help` marks these as aliases for `--openai-filter-command` / `--openai-filter-checkpoint` "in registry examples". The adapter forwards the canonical spellings (rows above); the aliases add no capability. |
| `--kiji-backend` | `gaze.kiji_backend` / `GAZE_KIJI_BACKEND` (v0.9; `subprocess` \| `ort`) |
| `--kiji-distilbert-precision` | `gaze.kiji_distilbert_precision` / `GAZE_KIJI_DISTILBERT_PRECISION` (v0.9; `fp32` \| `int8`) |
| `--kiji-distilbert-command` | `gaze.kiji_distilbert_command` / `GAZE_KIJI_DISTILBERT_COMMAND` (v0.8.x) |
| `--kiji-distilbert-model-dir` | `gaze.kiji_distilbert_model_dir` / `GAZE_KIJI_DISTILBERT_MODEL_DIR` (v0.8.x) |
| `--openai-filter-device` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` |
| `--openai-filter-command` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` |
| `--openai-filter-checkpoint` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` |
| `--openai-filter-operating-point` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` |
| `--safety-net-timeout-ms` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` |
| `--safety-net-input-limit-bytes` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` |
| `--safety-net-mode` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` (`strict` \| `tolerant` \| `redact` \| `resolve`; upstream default flipped `strict`→`resolve` in v0.8.1) |
| `--safety-net-fallback` | `gaze.safety_net_fallback` / `GAZE_SAFETY_NET_FALLBACK` (v0.8.x; `strict` \| `tolerant` \| `redact`; default `redact`) |

## Restore Flags

| Upstream flag | Laravel surface |
|---|---|
| `--format=json` | Always set by `Gaze::restore()` |
| `--max-bytes` | `gaze.max_bytes` / `GAZE_MAX_BYTES` |
| `--restore-mode` | `gaze.restore_mode` / `GAZE_RESTORE_MODE` |

## Exception Variants

| Upstream variant | Laravel exception |
|---|---|
| `StdinParse` | `GazeStdinParseException` |
| `EmptyInput` | `GazeEmptyInputException` |
| `InputTooLarge` | `GazeInputTooLargeException` |
| `InvalidEncoding` | `GazeInvalidEncodingException` |
| `PolicyConfig` | `GazePolicyConfigException` or `GazePolicyConfigDetailException` when `detail` exists; `detail()` accessor exposes the upstream sidecar |
| `PolicySchemaUnsupported` | `GazePolicySchemaUnsupportedException`; `found()` + `supported()` accessors expose the typed envelope fields |
| `SafetyNetConfig` | `GazeSafetyNetConfigException` |
| `SafetyNet` | `GazeSafetyNetFailureException` |
| `SafetyNetArtifactMissing` | `GazeSafetyNetArtifactMissingException`; `backend()` + `path()` accessors expose the typed envelope sidecars. Axis-1 fail-closed (exit 2) when a backend's pinned artifact (e.g. `SHA256SUMS` for the Kiji DistilBERT backend) is absent. |
| `AuditPurgeIso8601` | `GazeAuditPurgeIso8601Exception` |
| `UnknownToken` | `GazeUnknownTokenException` |
| `UnsupportedSessionScope` | `GazeUnsupportedSessionScopeException` |
| `InvalidSignature` | `GazeInvalidSignatureException` |
| `InvalidBlobVersion` | `GazeInvalidBlobVersionException` |
| `BlobExpired` | `GazeBlobExpiredException` |
| `Pipeline` | `GazePipelineException` |
| `Io` | `GazeIoException` |
| `SigPipe` | `GazeSigPipeException` |
| `PolicyOpen` | `GazePolicyOpenException` |

## Coverage by locale (v0.8.0)

Upstream v0.8.0 introduces 10 locale-gated entities across the new
`locale-{br,fr,nl,in,uk}` packs plus extensions to the existing US/UK
packs. All entities are additive — existing deployments see no
behaviour change unless `gaze.locale` / `GAZE_LOCALE` is set to a
matching BCP47 locale.

| Entity | Locale | ValidatorKind | Tier |
|---|---|---|---|
| Aadhaar | IN | `AadhaarVerhoeff` | 2 (safe_default) |
| NIR | FR | `FrNirMod97` | 2 (safe_default) |
| Steuer-ID | DE | `DeSteuerIdMod1110` | 2 (safe_default) |
| BSN | NL | `BsnMod11` | 2 (safe_default) |
| CPF | BR | `CpfMod11` | 2 (safe_default) |
| CNPJ | BR | `CnpjMod11` | 2 (safe_default) |
| NHS number | UK | `UkNhsMod11` | 2 (safe_default) |
| US SSN | US | `None` (cue-gated) | 3 (locale_gated) |
| UK NINO | UK | `None` (cue-gated) | 3 (locale_gated) |
| Indian PAN | IN | `None` (cue-gated) | 3 (locale_gated) |

The Laravel adapter forwards `--locale=<value>` via `gaze.locale` /
`GAZE_LOCALE`; no code change is needed to opt in. The value is passed
**verbatim**, and upstream parses it as a comma-separated,
priority-ordered fallback chain — so both a single BCP47 hint
(`GAZE_LOCALE=de`) and a chain (`GAZE_LOCALE=de-DE,en`) work today.

## Audit row columns (v0.8.0)

Upstream v0.8.0 adds two columns to the `gaze audit query` output:

| Column | Notes |
|---|---|
| `recognizer_id` | Stable string identifier for the recognizer that produced a span. |
| `recognizer_version_id` | `<recognizer_id>_v<N>` suffix; bumps on recognizer behaviour changes for replay-stability. |

`gaze audit query` prints **TSV**, and `Audit\QueryBuilder::execute()`
returns it as positional rows — `list<list<string>>`, where the FIRST row
is upstream's header line (column names). Rows are NOT keyed by string;
columns are located by matching against the header row. No columns are
stripped — everything upstream prints flows through verbatim. For
string-keyed rows (`$row['recognizer_version_id']`), use
`QueryBuilder::export()`: `gaze audit export` emits JSONL objects keyed by
column name, decoded by `AuditExportResult::rows()` for stdout exports.
A typed `AuditRow` DTO is tracked as a future ergonomics nicety, not a
blocker.

## Audit query/export filters (v0.11.x)

All `gaze audit query` / `gaze audit export` filter flags forward through
the fluent `Audit\QueryBuilder` (pure argv forwarding — no PHP-side
filtering). `--class` is a PHP reserved word as a method name, hence the
`where` prefix, kept consistent across the value filters:

| Upstream flag | Builder method |
|---|---|
| `--class` | `whereClass(string)` |
| `--source` | `whereSource(string)` |
| `--action` | `whereAction(string)` |
| `--document-kind` | `whereDocumentKind(string)` |
| `--from` | `from(CarbonInterface\|string)` (Carbon → ISO 8601 UTC Zulu) |
| `--to` | `to(CarbonInterface\|string)` (same normalisation) |
| `--session` | `whereSession(string)` |
| `--has-ambiguity` | `hasAmbiguity()` |
| `--ambiguity-reason` | `whereAmbiguityReason(string)` |
| `--collision-family` | `whereCollisionFamily(string)` |
| `--collision-variant` | `whereCollisionVariant(string)` |
| `--restore-events` | `onlyRestoreEvents()` |
| `--format` (export only) | `export()` `$format` arg, forwarded verbatim (upstream 0.11.x accepts only `jsonl`) |
| `--output` (export only) | `export()` `$output` arg; null exports to stdout, captured on `AuditExportResult` |

`gaze audit safety-net query` filters forward through
`Audit\SafetyNetQueryBuilder` the same way:

| Upstream flag | Builder method |
|---|---|
| `--leak-kind` | `whereLeakKind(string)` |
| `--raw-label` | `whereRawLabel(string)` |
| `--mapped-class` | `whereMappedClass(string)` |
| `--field-path` | `whereFieldPath(string)` |
| `--from` / `--to` | `from()` / `to()` (Carbon → ISO 8601 UTC Zulu) |

## Proxy (v0.8.1)

The upstream `gaze proxy` daemon (v0.8.0, opt-in `--features proxy` build)
is wrapped by six Artisan commands. See [`docs/proxy.md`](../how-to/proxy-daemon.md) for
the adopter quickstart, security notes, and the doctor probe.

| Upstream subcommand | Artisan surface |
|---|---|
| `gaze proxy serve` | `php artisan gaze:proxy:serve` |
| `gaze proxy start` | `php artisan gaze:proxy:start` |
| `gaze proxy stop` | `php artisan gaze:proxy:stop` |
| `gaze proxy restart` | `php artisan gaze:proxy:restart` |
| `gaze proxy status` | `php artisan gaze:proxy:status` |
| `gaze proxy logs` | `php artisan gaze:proxy:logs` |
| `gaze proxy install-launchd` | not wrapped — upstream stub in v0.8.0 (`"reserved for v0.8.x"`) |
| `gaze proxy install-systemd-user` | not wrapped — upstream stub in v0.8.0 (`"reserved for v0.8.x"`) |

| Upstream flag | Laravel surface |
|---|---|
| `--bind` | `gaze.proxy.bind` / `GAZE_PROXY_BIND` (default `127.0.0.1:8787`) |
| `--session-ttl` | `gaze.proxy.session_ttl` / `GAZE_PROXY_SESSION_TTL` (default `30m`) |
| `--rulepack` | `gaze.proxy.rulepack` / `GAZE_PROXY_RULEPACK` (default `core`) |
| `--policy` | `gaze.proxy.policy_path` / `GAZE_PROXY_POLICY_PATH` (default `null`) |
| `--upstream-openai` | `gaze.proxy.upstream.openai` / `GAZE_PROXY_UPSTREAM_OPENAI` |
| `--upstream-anthropic` | `gaze.proxy.upstream.anthropic` / `GAZE_PROXY_UPSTREAM_ANTHROPIC` |
| `--upstream-gemini` | `gaze.proxy.upstream.gemini` / `GAZE_PROXY_UPSTREAM_GEMINI` |
| `--timeout` (stop / restart) | `gaze.proxy.stop_timeout` / `GAZE_PROXY_STOP_TIMEOUT` (default `10s`) |
| `--force` (stop / restart) | `--force` artisan flag |
| `--follow` (logs) | `--follow` artisan flag |
| `--foreground-daemon` (serve) | `--foreground-daemon` artisan flag |

## SafetyNet backend & mode reshape (v0.8.1)

Upstream v0.8.1 introduces a backend selector for the Pass-3 safety net
plus a four-valued `safety_net_mode` enum and a typed fallback. All
surfaces are exposed via `gaze.*` config keys; defaults match upstream
when the key is null.

| Knob | Upstream default | Adapter key | Notes |
|---|---|---|---|
| `--safety-net-backend` | `openai-filter` | `gaze.safety_net_backend` | Set to `kiji-distilbert` to opt into the Tier 2.5 DistilBERT NER subprocess. Wins over the legacy `--safety-net=<kind>` flag when both are set. |
| `--kiji-distilbert-command` | (PATH lookup) | `gaze.kiji_distilbert_command` | Local Kiji binary path. |
| `--kiji-distilbert-model-dir` | (none — fails closed) | `gaze.kiji_distilbert_model_dir` | Pinned-artifact directory. Required when the backend is `kiji-distilbert`. |
| `--safety-net-mode` | `resolve` (v0.8.1; was `strict` ≤ v0.8.0) | `gaze.safety_net_mode` | Valid: `strict` \| `tolerant` \| `redact` \| `resolve`. `tolerant` emits a deprecation warning upstream. |
| `--safety-net-fallback` | `redact` | `gaze.safety_net_fallback` | Engages when `safety_net_mode` is `redact` or `resolve` and the active backend cannot complete. |

`php artisan gaze:doctor` adds a Kiji artifact pre-flight: when
`gaze.safety_net_backend === 'kiji-distilbert'`, doctor asserts the
model dir is set and carries `SHA256SUMS`, `labels.json`, `model.onnx`,
and `tokenizer.json` before the binary fails the first `gaze clean`
with a `SafetyNetArtifactMissing` envelope.

## Safety-net registry (v0.9.0)

Upstream v0.9.0 adds a **locale-aware Pass-3 safety-net registry**: instead
of one global backend, `--safety-net-registry` enables registry dispatch,
`--safety-net-add` registers one backend per use (repeatable), and
`--opf-locales` / `--kiji-distilbert-locales` scope each registry entry to a
locale list. All four flags are present on the pinned binary's
`gaze clean --help`.

The adapter does **not** expose this family yet — honest status: **deferred**,
not wrapped and not passthrough (no config key or argv reaches these flags).

| Upstream flag | Verdict | Notes |
|---|---|---|
| `--safety-net-registry` | **defer** | Boolean registry-dispatch switch. Single-backend selection (`gaze.safety_net_backend`) covers current adopters. |
| `--safety-net-add` | **defer** | Repeatable — needs a list-shaped config key (`gaze.safety_net_registry.backends`-style), which deserves design rather than an ad-hoc CSV env var. |
| `--opf-locales` | **defer** | Per-entry locale scoping for the OPF backend. Only meaningful once the registry itself is exposed. |
| `--kiji-distilbert-locales` | **defer** | Per-entry locale scoping for the Kiji DistilBERT backend. Same dependency. |
| `--opf-command` / `--opf-checkpoint` | no surface needed | Upstream aliases for the already-wrapped `--openai-filter-command` / `--openai-filter-checkpoint`. |

Wrap trigger: an adopter running multi-locale traffic that needs different
Pass-3 backends per locale. Until then the existing single
`--safety-net-backend` surface plus the `--locale` fallback chain is the
supported path. Tracked in [Deferred](#deferred).

## Daemon (v0.11.0)

Upstream `gaze daemon` is a long-lived JSONL stdio runtime. The adapter
exposes it via the `Gaze::daemon()` Facade chain, a flat config block,
and TWO artisan commands. See [docs/daemon.md](../how-to/daemon.md) for the
adopter quickstart.

The upstream binary pin is now **v0.11.3**. The v0.9.1 → v0.11.1 hardening
(NER fail-closed, byte-exact restore, strict manifest-restore) is all
passthrough — no new daemon flag — see [Upstream v0.9.1 → v0.11.1 deltas](#upstream-v091--v0111-deltas).
For the v0.11.2 pin deltas see [Upstream v0.11.1 → v0.11.2 deltas](#upstream-v0111--v0112-deltas);
for the v0.11.3 pin deltas see [Upstream v0.11.2 → v0.11.3 deltas](#upstream-v0112--v0113-deltas).

### Commands

| Upstream command | Laravel surface |
|---|---|
| `gaze daemon --policy=...` (foreground) | `php artisan gaze:daemon:serve` |
| n/a (best-effort PID lookup) | `php artisan gaze:daemon:status` |
| JSONL request `{"session_id","text"}` | `Gaze::daemon()->session($id)->clean($text)` / `Gaze::daemon()->clean($id, $text)` |

`:start`, `:stop`, `:restart`, `:logs` are intentionally NOT shipped —
supervision is OS-owned. Use systemd / Horizon / supervisord primitives.

### Daemon Flags

Both daemon spawn paths — `gaze:daemon:serve` AND the `Gaze::daemon()`
Facade hot path (`DaemonClient` spawn) — forward **every** flag the
pinned v0.11.1 `gaze daemon --help` surface accepts. Both paths build
their argv from the same assembler (`Daemon\DaemonArgv`), so they cannot
drift. Daemon-specific knobs live under `gaze.daemon.*`; the shared
pipeline flags source the same top-level `gaze.*` keys the one-shot
`Gaze::clean()` path forwards, so a configured pipeline behaves
identically in both runtimes. Flags marked *artisan option* can also be
overridden per-invocation on `gaze:daemon:serve`.

| Upstream flag | Laravel surface | Artisan option |
|---|---|---|
| `--policy=` | `gaze.daemon.policy_path` / `GAZE_DAEMON_POLICY_PATH` | `--policy=` |
| `--audit-db=` | `gaze.daemon.audit_db_path` / `GAZE_DAEMON_AUDIT_DB_PATH` | `--audit-db=` |
| `--idle-timeout=` | `gaze.daemon.idle_timeout_s` / `GAZE_DAEMON_IDLE_TIMEOUT_S` | `--idle-timeout=` |
| `--session-idle-timeout=` | `gaze.daemon.session_idle_timeout_s` / `GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S` | `--session-idle-timeout=` |
| `--session-cap=` | `gaze.daemon.session_cap` / `GAZE_DAEMON_SESSION_CAP` | `--session-cap=` |
| `--locale=` | `gaze.locale` / `GAZE_LOCALE` (shared with one-shot) | `--locale=` |
| `--ner-threshold=` | `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` (shared with one-shot) | `--ner-threshold=` |
| `--ner-model-dir=` | `gaze.daemon.ner_model_dir` / `GAZE_DAEMON_NER_MODEL_DIR` | config-only |
| `--ner-locale=` | `gaze.daemon.ner_locale` / `GAZE_DAEMON_NER_LOCALE` | config-only |
| `--safety-net=` | `gaze.safety_net` / `GAZE_SAFETY_NET` (truthy → `openai-filter`, mirroring one-shot) | config-only |
| `--safety-net-backend=` | `gaze.safety_net_backend` / `GAZE_SAFETY_NET_BACKEND` | config-only |
| `--openai-filter-device=` | `gaze.safety_net_device` / `GAZE_SAFETY_NET_DEVICE` | config-only |
| `--openai-filter-command=` | `gaze.openai_filter_command` / `GAZE_OPENAI_FILTER_COMMAND` | config-only |
| `--openai-filter-checkpoint=` | `gaze.openai_filter_checkpoint` / `GAZE_OPENAI_FILTER_CHECKPOINT` | config-only |
| `--openai-filter-operating-point=` | `gaze.openai_filter_operating_point` / `GAZE_OPENAI_FILTER_OPERATING_POINT` | config-only |
| `--kiji-backend=` | `gaze.kiji_backend` / `GAZE_KIJI_BACKEND` | config-only |
| `--kiji-distilbert-command=` | `gaze.kiji_distilbert_command` / `GAZE_KIJI_DISTILBERT_COMMAND` | config-only |
| `--kiji-distilbert-model-dir=` | `gaze.kiji_distilbert_model_dir` / `GAZE_KIJI_DISTILBERT_MODEL_DIR` | config-only |
| `--kiji-distilbert-locales=` | `gaze.daemon.kiji_distilbert_locales` / `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES` (no one-shot equivalent) | config-only |
| `--safety-net-timeout-ms=` | `gaze.safety_net_timeout_ms` / `GAZE_SAFETY_NET_TIMEOUT_MS` | config-only |
| `--safety-net-input-limit-bytes=` | `gaze.safety_net_input_limit_bytes` / `GAZE_SAFETY_NET_INPUT_LIMIT_BYTES` | config-only |
| `--safety-net-mode=` | `gaze.safety_net_mode` / `GAZE_SAFETY_NET_MODE` | config-only |
| `--safety-net-fallback=` | `gaze.safety_net_fallback` / `GAZE_SAFETY_NET_FALLBACK` | config-only |
| n/a (adapter-side ceiling) | `gaze.daemon.request_timeout_ms` / `GAZE_DAEMON_REQUEST_TIMEOUT_MS` (default 5000) | — |
| n/a (adapter spawn override) | `gaze.daemon.binary_path` / `GAZE_DAEMON_BINARY_PATH` | — |
| n/a (adapter spawn stderr) | `gaze.daemon.stderr_path` / `GAZE_DAEMON_STDERR_PATH` | — |

Note: `--kiji-distilbert-precision` exists on `gaze clean` but NOT on
`gaze daemon` in v0.11.1, so neither daemon spawn path forwards it.

Intentionally NOT shipped: `gaze.daemon.events.enabled` (reserved
P1-violation), `gaze.daemon.extra_flags` (P3 velocity signal),
connections-style `gaze.daemon.connections.{name}.*` (additive MINOR
once a second adopter files).

### Errors

`Gaze::daemon()` calls throw the `GazeDaemonException` family. Variants
are exposed via `DaemonErrorVariant` so adopter `match()` ladders react
per-variant. **`default` arm is required** — new wire variants land in
`DaemonErrorVariant::Unknown`.

| Wire variant | Exception subclass | Adapter posture |
|---|---|---|
| `JsonMalformed` | `GazeDaemonException` | Adapter framing bug |
| `Pipeline` | `GazeDaemonException` | Upstream fail-closed |
| `Transport` (adapter) | `GazeDaemonTransportException` | EOF / broken pipe / session id mismatch — fail-closed, no auto-reconnect |
| `Timeout` (adapter) | `GazeDaemonTimeoutException` | Per-request `gaze.daemon.request_timeout_ms` exceeded |
| `Unavailable` (adapter) | `GazeDaemonFeatureUnsupportedException` | Binary missing `daemon` subverb |
| `Unknown` (forward-compat) | `GazeDaemonException` | New upstream variant; doctor logs adopter warning |

## Upstream v0.9.1 → v0.11.1 deltas

Gap analysis for upstream changes landed since the v0.9.0 parity baseline.
Verdicts follow the surface-promotion rule ([NORTH_STAR](../NORTH_STAR.md) §3):
`wrap` = new Laravel surface, `passthrough` = forwarded argv with no new
adopter surface, `defer` = documented non-goal.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| NER fail-closed (#290/#293), byte-exact restore (#295), strict manifest-restore (#262, MCP-only) + binary pin bump | passthrough | PATCH | Detection / restore-determinism hardening upstream; nothing new for the adapter to forward beyond the existing pin. Reinforces reversibility (NORTH_STAR §4), changes no surface. |
| Restore telemetry + audit columns (#261/#270) | **wrap** | MINOR | New opt-in adopter surface — see [Restore telemetry (v0.11.x)](#restore-telemetry-v011x) below. |
| Clean `leak_report` (verification signal on `gaze clean --format=json`) | **wrap** | MINOR | The adapter previously dropped this field. Now surfaced as a typed `LeakReport` + `CoverageState` trust state on `GazeSession` — see [Clean leak report & trust state (v0.11.x)](#clean-leak-report--trust-state-v011x) below. |
| TokenBridge index-search (#327) | **defer** | none | Verdict as adjudicated against v0.11.1. The "unencrypted on disk" leg of this rationale was resolved upstream in v0.11.2 (encrypted indexes at rest) — see the re-adjudicated entry in [Deferred](#deferred). |
| `gaze-mcp-bridge` (#330) | **defer** | none | MCP server lifecycle — explicit non-goal. See Deferred. |
| CLI accessibility gate (#287) | internal-only | none | Human-TTY affordance; the adapter always invokes with `--format=json`, so the gate never engages. No surface. |
| `core-extended` rulepack | still-available alias | n/a | `gaze:doctor`'s "Removal target: v0.10.0" line was **stale** — upstream never removed the pack. It still soft-aliases through v0.11.3; documented as available, not removed. See [upgrading.md](../how-to/upgrading.md). |
| `gaze-document` split (#279) | already-covered | none | OCR / document pipeline stays a deferred non-goal. See Deferred. |

## Upstream v0.11.1 → v0.11.2 deltas

Gap analysis for the v0.11.2 pin bump (upstream released 2026-06-23). Same
verdict vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| New default recognizers: EU VAT IDs, ISO-length-gated IBANs, spaced international E.164 phones | passthrough | PATCH | Detection additions in the default recognizer set — adopters get them purely by taking the pin. No new flag, no new adapter surface. |
| NER loader fix for the Kiji bundle (optional `config.json`, conditional `token_type_ids`) | passthrough | PATCH | Fixes the `kiji-distilbert` backend load path. The existing `gaze.kiji_*` config keys forward unchanged. |
| Proxy PII-surface + email-TLD recognizer hardening | passthrough | PATCH | Correctness fixes inside the binary; nothing to forward. |
| `gaze setup` (one-command onboarding: NER install + policy + doctor) | **defer** | none | The Laravel onboarding path is already covered by `php artisan gaze:install` / `gaze:install:ner` + `gaze:doctor`, which additionally handle the adapter-side pieces (config publish, binary pin) that upstream `setup` knows nothing about. Delegating those artisans to `gaze setup` internally is a future option, not a gap. |
| TokenBridge: encrypted indexes at rest (ChaCha20-Poly1305, `GAZE_INDEX_KEY`, `os-keychain`), `gaze index ingest --on-residual redact\|strict`, real error detail | **defer** | none | Removes the plaintext-PII blocker from the v0.11.1 adjudication; the surface is now a **promotion candidate** — see the re-adjudicated entry in [Deferred](#deferred). |

## Upstream v0.11.2 → v0.11.3 deltas

Gap analysis for the v0.11.3 pin bump (upstream released 2026-07-03). Verified
against the real 0.11.3 macOS arm64 binary (sha256-pinned): every `gaze --help`
and subcommand-help contract snapshot is **byte-identical to v0.11.2** — no new,
changed, or removed flag, so there is **no surface to promote**. Same verdict
vocabulary as above.

| Upstream change | Verdict | Adapter SemVer | Notes |
|---|---|---|---|
| Supply-chain hygiene: pdfium build-input pin, dead `daemonize` dependency dropped | passthrough | PATCH | Upstream dependency-graph hardening. Nothing crosses the CLI contract; adopters inherit it purely by taking the pin. No flag, no surface. |
| Leak fixes (observer-only safety-net correctness) | passthrough | PATCH | Correctness fixes inside the binary's safety-net path. The `LeakReport` / `LeakSuspect` projection shape is unchanged — the contract enum + round-trip suites pass against the real 0.11.3 binary. |
| `restore` token-ordinal parsing tightened to ASCII digits only | passthrough | PATCH | Hardens `restore` against malformed/adversarial token ordinals. The clean/restore round trip is byte-identical against the real binary (reversibility, NORTH_STAR §4); not adopter-observable through this package's wire shape. |
| Property-test infra + restore cache (upstream internal) | n/a | none | Upstream test/perf internals. No CLI-contract effect, nothing to forward. |

## Restore telemetry (v0.11.x)

Upstream's restore-telemetry + audit-column work (#261/#270) is **wrapped**
as an opt-in adapter surface. Off by default (null = upstream default,
NORTH_STAR §6).

| Surface | Detail |
|---|---|
| Config / env | `gaze.restore_telemetry` / `GAZE_RESTORE_TELEMETRY` — default `null` (off) |
| `Gaze::restore()` | When enabled, forwards `--telemetry --audit-db=<gaze.audit_db_path>` |
| `CertaMesh\Gaze\Audit\QueryBuilder::onlyRestoreEvents()` | Forwards `--restore-events` to scope an audit query to restore rows |
| `--policy` restore alias | Redundant with the already-forwarded `--restore-mode`; **document-only, NO new Laravel surface** |

Six new audit columns surface through `Audit\QueryBuilder` — positional
TSV columns located via the header row (or string-keyed via `export()`),
like the v0.8.0 recognizer columns:

| Column | Notes |
|---|---|
| `restore_policy` | Restore policy in effect for the row. |
| `restore_decision` | Per-row restore decision. |
| `restore_unknown_token_count` | Count of tokens with no mapping in the session blob. |
| `restore_manifest_bypass_count` | Manifest-bypass count. **Always `0`** through the stock gaze CLI (see caveat). |
| `restore_fresh_pii_count` | Fresh-PII count. **Always `0`** through the stock gaze CLI (see caveat). |
| `restore_phase_mask` | Bitmask of restore phases that executed. |

> **Caveat —** `restore_fresh_pii_count` and `restore_manifest_bypass_count`
> are ALWAYS `0` through the stock gaze CLI — gaze-cli's `run_restore` never
> enables the Phase-B DLP builder. This surface ships for
> **restore-decision / unknown-token audit trails, NOT outbound-DLP fresh-PII
> detection.** Do NOT advertise the DLP use-case.

## Clean leak report & trust state (v0.11.x)

`gaze clean --format=json` always emits a `leak_report` object — the upstream
pipeline's own coverage check. The adapter previously **dropped** it, leaving
callers to infer safety from the detection count. That over-asserts: a high
detection count never proves a span did not bleed through (NER can fire many
times while a real PII value stays uncovered). The report is now **wrapped** as
a typed, metadata-only DTO and a derived trust state on every `GazeSession`.

| Surface | Detail |
|---|---|
| `GazeSession::$leakReport` | `?CertaMesh\Gaze\LeakReport` — the parsed report, or `null` when the binary emits no `leak_report` |
| `CertaMesh\Gaze\LeakReport` | Counts (`suspectCount`, `uncoveredCount`, `partialBleedCount`, `classMismatchCount`, `localeSkippedCount`), a `list<LeakSuspect> $suspects`, optional `$replayHash` |
| `CertaMesh\Gaze\LeakSuspect` | Per-suspect **metadata only**: `safetyNetId`, `rawLabel` (backend category label, never source text), `mappedClass`, `leakKind`, `pipelineClass`, `spanLen`, `fieldPath`, `score` |
| `GazeSession::coverageState(): CoverageState` | `Verified` (green) \| `Unverified` (amber) \| `Suspect` (red) |
| `GazeSession::hasSuspectedLeak(): bool` | `true` only when the safety net actively flagged a span |

**Trust-state semantics** ([why a green count over-asserts](../explanation/security.md#trust-state-a-count-is-not-a-verification)):

| State | When | Meaning |
|---|---|---|
| `Suspect` (red) | `suspect_count > 0` | The observer-only safety net flagged a span that may still carry raw PII. Hardest signal — wins over amber. |
| `Unverified` (amber) | no suspects, but any of `uncovered_count` / `partial_bleed_count` / `class_mismatch_count` / `locale_skipped_count` > 0 — **or `leak_report` absent** | Coverage is partial, or there is no upstream verification to back a green. Never silently promoted to green. |
| `Verified` (green) | no suspects **and** no coverage gaps | Upstream's coverage check passed. Not "N detections" — an actual verification. |

The report is **metadata only**: upstream serialises no source text and no byte
offsets (only `span_len` survives; `raw_label` is the backend's category label).
The adapter doubles down — `LeakReport`/`LeakSuspect` read a strict field
allowlist, so a future or tampered upstream field carrying raw text can never
flow through (enforced by a hostile-fixture test).

> **Caveat —** the `suspect_count` / `suspects` channel is populated by the
> observer-only **Pass-3 safety net**, which is a **compile-time feature absent
> from the stock release binary**. Through the stock CLI those stay `0` / empty,
> so the strongest reachable state is `Unverified` — `Suspect` (red) lights up
> only when an adopter runs a safety-net-enabled build (`--features
> safety-net-openai`). The four coverage-gap counts come from the core pipeline
> and are always present. This mirrors the restore-telemetry caveat: the surface
> ships forward-compatible; do NOT advertise stock-CLI safety-net leak detection.

## Deferred

| Upstream surface | Reason |
|---|---|
| Per-detection byte spans (`start` / `end`) on `gaze clean --format=json` entries | **Upstream feature request.** As of the v0.11.3 pin, clean `--format=json` `entries[]` keys are exactly `{class, raw, token, family}` — there are **no byte offsets**. Computing span positions in PHP is a NORTH_STAR non-goal (it would re-derive detection geometry outside upstream). Blocked on upstream adding per-detection byte spans (start/end) to the clean `--format=json` contract; until then `Gaze::mask()` ships on the collision-safe token map instead. A `length()` / offset accessor on `Entry`/`GazeSession` lands as an additive MINOR once upstream emits the spans. |
| `--context-json` | P1 design item; needs PHP API design before exposure. |
| `gaze mcp install --client=<name>` / `gaze mcp doctor` / `gaze mcp serve` | Opt-in `mcp` feature in upstream v0.7.0; needs `php artisan gaze:mcp:*` artisan surface design. Tracked separately. |
| `gaze-mcp-bridge` (#330) | MCP server lifecycle — explicit NORTH_STAR non-goal. Not a Laravel idiom; lives upstream. Tracked with the other `gaze mcp *` surfaces above. |
| `gaze setup` (v0.11.2 one-command onboarding) | The Laravel path is covered by `php artisan gaze:install` / `gaze:install:ner` + `gaze:doctor`, which also handle the adapter-side pieces (config publish, pinned-binary install) that upstream `setup` does not know about. Delegating the artisans to `gaze setup` internally is a future option; a separate wrap would only duplicate the surface. |
| TokenBridge index-search (`gaze index`, #327) | **Re-adjudicated at the v0.11.2 pin.** The v0.11.1 deferral leaned on two legs: (1) indexes persisted raw PII **unencrypted on disk** — **resolved upstream in v0.11.2** (ChaCha20-Poly1305 per-index encryption at rest, `GAZE_INDEX_KEY`, optional `os-keychain`; plus `gaze index ingest --on-residual redact\|strict` for residual safety-net hits); (2) the search flow routes through an MCP chokepoint, and owner-side gated search over redacted corpora sits outside this package's thin clean/restore gate — **still holds**. Verdict stays **defer** on leg 2 alone, but the surface is now a **promotion candidate**: a wrap would be `php artisan gaze:index:ingest` / `gaze:index:search` artisans plus a `gaze.index_key` / `GAZE_INDEX_KEY` config passthrough (key material handled like `GAZE_ENCRYPTION_KEY`, never logged). Promote once an adopter files a concrete Laravel-side use case. |
| `gaze document clean <input> --out <dir>` | Opt-in `document` feature in upstream v0.7.1 (Tesseract + pdfium); needs `Gaze::document()` facade or `php artisan gaze:document:clean` design. The v0.11.x `gaze-document` split (#279) keeps OCR a non-goal — still deferred, not re-scoped. Tracked separately. |
| `Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds, `eth.address` in published policy | Upstream v0.7.0 additions. Tracked for v0.8.x adapter release. |
| `gaze proxy install-launchd` / `install-systemd-user` | Upstream stubs the launchd / systemd integrations in v0.8.0 (return `"reserved for v0.8.x"`). Adapter will ship `php artisan gaze:proxy:install` once upstream implements them. |
| `gaze clean --ner-model-dir` / `--ner-locale` (runtime NER overrides) | Runtime overrides of policy `[ner].model_dir` / `[ner].locale` — distinct from the **install-time** variants the adapter already owns (`gaze:install:ner --dest --locale` writes them into `policy.toml`). Currently **not exposed**: no config key or per-call arg forwards them. Deferring keeps one source of truth for NER placement (the policy file `gaze:doctor` validates); a per-request model-dir swap has no adopter demand yet. Wrap-later candidate: `gaze.ner_model_dir` / `gaze.ner_locale` config passthrough (additive MINOR) once an adopter needs per-environment model dirs without policy edits. |
| Safety-net registry family (`--safety-net-registry`, `--safety-net-add`, `--opf-locales`, `--kiji-distilbert-locales`, v0.9.0) | Locale-aware Pass-3 registry dispatch — see [Safety-net registry (v0.9.0)](#safety-net-registry-v090) for per-flag verdicts. Not exposed; the single-backend `gaze.safety_net_backend` surface covers current adopters. Wrap once an adopter needs per-locale backend routing (list-shaped config, additive MINOR). |

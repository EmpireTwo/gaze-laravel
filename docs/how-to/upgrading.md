# Upgrading

Per-minor upgrade guide for `certamesh/gaze-laravel`. Pair with
[CHANGELOG.md](../../CHANGELOG.md) and the upstream binary's
[UPGRADE.md](https://github.com/CertaMesh/gaze/blob/main/UPGRADE.md).

## v0.11.1 → v0.12.0 (Unreleased)

> **Canonical guide: [UPGRADING.md](../../UPGRADING.md) at the repo root.**
> This release is BREAKING on identity only: the Composer package renames
> `empiretwo/gaze-laravel` → `certamesh/gaze-laravel` (swap the requirement
> and the `config.allow-plugins` key) and the PHP root namespace renames
> `Naoray\GazeLaravel` → `CertaMesh\Gaze` (mechanical `use`-statement replace;
> the `Gaze` facade alias is unchanged). It also adds the `GazeSession`
> coverage/trust state (`coverageState()` / `hasSuspectedLeak()`) and a
> per-call NER threshold (`Gaze::clean($text, threshold: 0.65)` with
> `gaze.ner_threshold` / `GAZE_NER_THRESHOLD` as the default). Full steps,
> before/after snippets, and the queued-payload caveat live in the root
> [UPGRADING.md](../../UPGRADING.md).

## v0.11.2 → v0.11.3

> Keyed by the **upstream binary pin** (advances from `gaze` v0.11.2 to
> **v0.11.3**). Pure pin-forward — supply-chain hygiene, leak fixes, and a
> restore-parser tightening; no new adapter surface, no new flag, no
> wire-contract change. The clean/restore round trip is unchanged.

1. **Binary pin bump `0.11.2` → `0.11.3`.** `BinaryDownloader::PINNED_VERSION`
   is now `0.11.3`; `composer install` / `composer update` re-downloads and
   SHA256-verifies the pinned binary. Hold the previous binary temporarily with
   `GAZE_VERSION=0.11.2` while you validate.
2. **Supply-chain hygiene — no adopter action.** Upstream v0.11.3 pins the
   pdfium build input and drops a dead `daemonize` dependency. You inherit the
   tighter upstream dependency graph purely by taking the pin.
3. **Restore token-ordinal parsing tightened.** Upstream now parses restore
   token ordinals as ASCII digits only. The adapter's clean/restore round trip
   is byte-identical (verified against the real 0.11.3 binary); the change is a
   correctness hardening for malformed/adversarial token input, not a
   wire-contract change adopters can observe. No config change needed.
4. **Help surface unchanged.** Every `gaze --help` / subcommand-help contract
   snapshot is byte-identical to v0.11.2 — no new, changed, or removed flag, so
   there is no new surface to promote.

### Action required

- **None.** Run `composer install` (or `composer update certamesh/gaze-laravel`)
  and confirm `php artisan gaze:doctor` reports the pinned binary at `0.11.3`.

## v0.11.1 → v0.11.2

> Keyed by the **upstream binary pin** (advances from `gaze` v0.11.1 to
> **v0.11.2**). Pure pin bump — no new adapter surface, no wire-contract
> change; the clean/restore round trip is unchanged.

1. **Binary pin bump `0.11.1` → `0.11.2`.** `BinaryDownloader::PINNED_VERSION`
   is now `0.11.2`; `composer install` / `composer update` re-downloads and
   SHA256-verifies the pinned binary. Hold the previous binary temporarily with
   `GAZE_VERSION=0.11.1` while you validate.
2. **New default recognizers — free coverage, no adopter action.** Upstream
   v0.11.2 detects EU VAT IDs, ISO-length-gated IBANs, and spaced international
   E.164 phone numbers by default. Adopted purely by taking the pin.
3. **NER loader fix for the Kiji bundle.** Detection NER now loads the shipped
   Kiji model bundle (optional `config.json` metadata, conditional
   `token_type_ids`). Relevant if you use the `kiji-distilbert` safety-net
   backend; no config change needed.
4. **`gaze setup` (upstream one-command onboarding) is not wrapped.**
   `php artisan gaze:install` / `gaze:install:ner` already cover the Laravel
   onboarding path — see
   [upstream-coverage.md](../reference/upstream-coverage.md#upstream-v0111--v0112-deltas).
5. **TokenBridge indexes are now encrypted at rest upstream.** The `gaze index`
   surface stays unwrapped for now, but the 0.11.1 plaintext-PII deferral
   rationale no longer applies — see the re-adjudicated entry in
   [upstream-coverage.md](../reference/upstream-coverage.md#deferred).

### Action required

- **None.** Run `composer install` (or `composer update empiretwo/gaze-laravel`)
  and confirm `php artisan gaze:doctor` reports the pinned binary at `0.11.2`.

## v0.9.0 → v0.11.1

> Keyed by the **upstream binary pin** (advances from `gaze` v0.9.0 to
> **v0.11.1**), shipped in gaze-laravel **v0.11.1**. Two legs: (a) upstream
> correctness fixes adopted purely through the binary — no adapter code, no
> adopter action; (b) one net-new wrap-tier Laravel surface (restore
> telemetry). The restore JSON wire contract and exit codes are unchanged, so
> the reversible clean/restore round trip stays byte-for-byte compatible across
> the jump. (For the v0.10.0 → v0.11.0 adapter-only daemon surface, which left
> the binary pinned at v0.9.0, see the section below.)

### TL;DR

1. **Binary pin bump `0.9.0` → `0.11.1`.** `BinaryInstaller::PINNED_VERSION`
   is now `0.11.1`; `composer install` / `composer update` re-downloads and
   SHA256-verifies the pinned binary from the CertaMesh/gaze release. Hold the
   previous binary temporarily with `GAZE_VERSION=0.9.0` while you validate.
2. **NER fail-closed + byte-exact restore — no adopter action.** Upstream
   NER fail-closed (#290/#293) and byte-exact restore (#295) are pure binary
   correctness fixes. The PHP adapter forwards them unchanged; the restore
   JSON wire contract and exit codes are identical, so existing session blobs
   round-trip without migration. You adopt these purely by taking the pin —
   detection/NER/policy stays upstream, never re-implemented in PHP.
3. **New restore-telemetry surface (opt-in, default off).** Set
   `gaze.restore_telemetry` (config) or `GAZE_RESTORE_TELEMETRY` (env) to
   enable. When on, `Gaze::restore()` forwards
   `--telemetry --audit-db=<gaze.audit_db_path>`, recording six audit columns:
   `restore_policy`, `restore_decision`, `restore_unknown_token_count`,
   `restore_manifest_bypass_count`, `restore_fresh_pii_count`,
   `restore_phase_mask`. Query restore-only rows with
   `CertaMesh\Gaze\Audit\QueryBuilder::onlyRestoreEvents()` (forwards
   `--restore-events`). Default `null` = off = upstream default (no telemetry).
4. **Telemetry caveat — audit trail, NOT DLP.** `restore_fresh_pii_count` and
   `restore_manifest_bypass_count` are **always `0`** through the stock CLI:
   gaze-cli's `run_restore` never enables the Phase-B DLP builder. This surface
   exists for restore-decision and unknown-token audit trails (did a restore
   run strict vs tolerant, how many unknown tokens were encountered) — it is
   **not** outbound-DLP fresh-PII detection. Do not build DLP controls on those
   two columns.
5. **`--policy` is a documentation-only alias.** Upstream's restore `--policy`
   flag is redundant with the already-forwarded `--restore-mode`; the adapter
   does **not** add a separate surface for it. Keep driving unknown-token
   handling through the existing restore-mode config.
6. **`core-extended` rulepack still a soft alias.** Through v0.11.x, the legacy
   `core-extended` rulepack emits an upstream soft warning and resolves to
   `core` — it is not a hard error, and `gaze:doctor` reports it as
   informational. No action required; silence the warning by switching to
   `core` plus an explicit `--locale` (or `GAZE_LOCALE`) when you are ready.

### Action required

- **None** for the correctness fixes — taking the v0.11.1 pin is sufficient.
  Run `composer install` (or `composer update empiretwo/gaze-laravel` — the
  package name at the v0.11.x line; renamed to `certamesh/gaze-laravel` in
  v0.12.0) and
  confirm `php artisan gaze:doctor` reports the pinned binary at `0.11.1`.
- **Only if you want restore audit trails:** set `GAZE_RESTORE_TELEMETRY=1`
  and a `gaze.audit_db_path`, then read restore rows via
  `QueryBuilder::onlyRestoreEvents()`.

### Additive

- **Config / env:** `gaze.restore_telemetry` / `GAZE_RESTORE_TELEMETRY`
  (default `null` = off = upstream default).
- **Facade:** `Gaze::restore()` forwards `--telemetry --audit-db=<…>` when
  telemetry is enabled.
- **Audit query:** `Audit\QueryBuilder::onlyRestoreEvents()` → `--restore-events`.
- **Six restore audit columns** (two — `restore_fresh_pii_count`,
  `restore_manifest_bypass_count` — are always `0` via the stock CLI; see the
  caveat above).

> See [docs/how-to/audit-query-export.md](./audit-query-export.md) for
> restore-event queries and
> [docs/reference/configuration.md](../reference/configuration.md) for the
> `restore_telemetry` key and audit-db wiring.

## v0.10.0 → v0.11.0

> Pre-1.0 SemVer MINOR bump: net-new adopter surface in four legs of the
> NORTH_STAR Principle 3 Laravel-idiom test (Facade method, artisan
> commands, config keys, env vars). Binary pin is unchanged at upstream
> v0.9.0 — this is purely adapter surface promotion. No breaking
> changes to existing v0.10.0 surfaces.

### TL;DR

1. **Optional rebuild for daemon feature.** If the GitHub-release `gaze`
   binary on your hosts is built without `--features daemon`, rebuild
   with `cargo install gaze-cli --features daemon` to enable the new
   `Gaze::daemon()` surface. The one-shot `Gaze::clean()` /
   `Gaze::restore()` path is unaffected — daemon is purely additive.
2. **New `Gaze::daemon()` Facade.** Use for multi-turn agent loops or
   worker queues that need repeated low-latency redaction without
   paying binary startup + Kiji ORT init per turn. See
   [docs/daemon.md](./daemon.md) for the adopter quickstart, including
   the reversibility caveat (daemon is clean-only — restore stays on
   the one-shot signed-blob contract).
3. **`DaemonSession` is NOT queueable.** `serialize($session)` throws
   `\LogicException`. The bound `DaemonClient` is process-local —
   queueing would hand a worker a stale handle to a daemon it never
   saw. Resolve a fresh `Gaze::daemon()->session($id)` per worker
   tick instead.
4. **Octane / Swoole.** `DaemonClient` is bound via `app()->scoped()`,
   so each request gets its own client and subprocess. Within a
   request, a per-request mutex serialises concurrent fiber-resident
   callers; mismatched `session_id` echoes surface as
   `GazeDaemonTransportException` before payloads leak cross-tenant.
5. **`match($variant)` requires a `default` arm.** New upstream wire
   variants land in `DaemonErrorVariant::Unknown` rather than
   throwing — your forward-compat handling must include a `default`
   case or you'll hit `UnhandledMatchError` on a future binary
   release.
6. **Doctor probe extension.** `php artisan gaze:doctor` now surfaces a
   daemon section when `gaze.daemon.policy_path` is set. Sections
   include: feature-gate pre-flight (`gaze daemon --help`), policy
   path readability, optional audit DB / stderr path writability
   checks.
7. **Two new artisan commands, four intentionally absent.**
   `php artisan gaze:daemon:serve` (foreground, supervisor-friendly)
   and `php artisan gaze:daemon:status` (best-effort PID lookup) are
   the only daemon artisans. `:start`, `:stop`, `:restart`, `:logs`
   are NOT shipped — supervision is OS-owned and adopters use
   systemd / Horizon / supervisord primitives.

### Migration checklist

- [ ] Decide whether daemon mode benefits your workload. One-shot stays
      first-class.
- [ ] If using daemon: set `GAZE_DAEMON_POLICY_PATH` (or
      `gaze.daemon.policy_path` in `config/gaze.php`).
- [ ] If using daemon: choose a supervisor and configure it to invoke
      `php artisan gaze:daemon:serve`. Forward `SIGTERM` for graceful
      shutdown — the wrapper installs pcntl handlers and forwards to
      the child.
- [ ] Audit any code that serialises a `DaemonSession` (it throws);
      replace with fresh per-request resolution.
- [ ] Audit any `match($e->daemonVariant())` block to confirm the
      `default` arm exists.
- [ ] Run `php artisan gaze:doctor` and resolve any surfaced
      cargo-install / path-readable warnings.

## v0.8.1 → v0.9.0

> Pre-1.0 SemVer MINOR bump: binary pin advances to upstream `gaze`
> v0.9.0 final plus net-new feature surface (Kiji backend selector with
> ORT int8 reach, three Kiji + fallback flags,
> `SafetyNetArtifactMissing` typed exception, new `Variant` case, new
> `DoctorCommand` probe) and the upstream default flip on
> `safety_net_mode`. Patch framing (v0.8.2) was rejected in review —
> additive features land on MINOR.

### TL;DR

1. **Binary pin bump.** `BinaryInstaller::PINNED_VERSION` advances to
   upstream `gaze` `0.9.0`. Pin `GAZE_VERSION=0.8.1` temporarily if you
   need to hold the previous binary while validating.
2. **Upstream `safety_net_mode` default flipped `strict`→`resolve`.** v0.8.1
   binaries no longer treat unset `--safety-net-mode` as `strict`; the new
   default is `resolve`, which Pass-3 routes suspected leaks through the
   active backend before redacting. Adopters who relied on the legacy
   strict-as-default behaviour must set `GAZE_SAFETY_NET_MODE=strict`
   explicitly. No code changes required on the adapter side — the binary
   applies the new default when the flag is omitted.
3. **Kiji DistilBERT safety-net backend (opt-in), ORT int8 reachable.** A
   new `gaze.safety_net_backend` config knob accepts `kiji-distilbert` to
   route Pass-3 through the upstream Tier 2.5 DistilBERT NER subprocess.
   Paired with `gaze.kiji_distilbert_command` /
   `gaze.kiji_distilbert_model_dir` so adopters can pin the local binary
   + artifact directory. To enable the v0.9 ORT int8 path, set
   `GAZE_SAFETY_NET=1`, `GAZE_SAFETY_NET_BACKEND=kiji-distilbert`,
   `GAZE_KIJI_BACKEND=ort`, and `GAZE_KIJI_DISTILBERT_PRECISION=int8`
   alongside your Kiji model directory.
4. **`SafetyNetArtifactMissing` exception.** When the Kiji (or any
   future pinned-artifact) backend can't find its required files,
   upstream emits a typed envelope at exit 2; the adapter now throws
   `GazeSafetyNetArtifactMissingException` with `backend()` + `path()`
   accessors. Inherits `NonRetryable` — queue retry policy fails fast.
5. **`gaze:doctor` Kiji pre-flight.** When `gaze.safety_net_backend` is
   `kiji-distilbert`, doctor verifies the model directory carries
   `SHA256SUMS`, `labels.json`, `model.onnx`, and `tokenizer.json` before
   the binary fails closed on the first invocation.
6. **Daemon restore is deferred.** Upstream `gaze daemon` in v0.9.0 is
   clean-only JSONL and does not return the signed `session_blob`
   required by `Gaze::restore()`. The PHP adapter keeps the one-shot
   clean/restore contract for reversible round trips.

### Action required

- Only if you relied on `safety_net_mode=strict` as an implicit default:
  set `GAZE_SAFETY_NET_MODE=strict` in your environment (or
  `gaze.safety_net_mode` in published config) to keep pre-v0.8.1
  behaviour. Everyone else: no action.

### Additive

- **Four new clean flags** wrap upstream's Kiji + fallback surfaces:
  `--safety-net-backend`, `--kiji-distilbert-command`,
  `--kiji-distilbert-model-dir`, `--safety-net-fallback`. All four config
  keys default to null (defer to upstream).
- **`safety_net_mode` accepts `redact` and `resolve`** in addition to the
  legacy `strict` / `tolerant`. The `tolerant` value emits a deprecation
  warning upstream.
- **Kiji backend + precision selectors.** `gaze.kiji_backend` /
  `GAZE_KIJI_BACKEND` forwards `--kiji-backend`;
  `gaze.kiji_distilbert_precision` / `GAZE_KIJI_DISTILBERT_PRECISION`
  forwards `--kiji-distilbert-precision`. Required for the v0.9 ORT int8
  path.
- CI pins `GAZE_VERSION=0.9.0` so installer tests and future integration
  jobs exercise the intended upstream tag by default.

> See [docs/safety-net.md](./safety-net.md) for the adopter usage guide for both SafetyNet backends.

## v0.7.x → v0.8.0

### TL;DR

1. **Bundle unification.** The published `resources/policy.toml` now ships
   `bundled = ["core"]` only. Adopters who copied the previous version
   with `["core", "core-extended"]` should drop `core-extended` and pass
   a locale flag (`GAZE_LOCALE=de-DE` or similar; a comma-separated
   priority fallback chain like `GAZE_LOCALE=de-DE,en` is also accepted
   and forwarded verbatim) to keep
   `phone.national.*` / `postal.*` coverage.
2. **Binary pin bump.** `BinaryInstaller::PINNED_VERSION` jumps from
   `0.7.2` to `0.8.0`. The `GAZE_VERSION` env override is still honoured
   if you want to stay on v0.7.x temporarily.
3. **10 new locale-gated entities.** Set `GAZE_LOCALE` to opt in to
   Aadhaar / NIR / Steuer-ID / BSN / CPF / CNPJ / NHS / SSN / NINO / PAN
   coverage. All additive — no behaviour change without the locale flag.

### Action required

- Only if `core-extended` appears in your `gaze.rulepacks` config or in
  your application's `policy.toml`: edit those files to drop
  `core-extended` and set an explicit `--locale` (or `GAZE_LOCALE`). Run
  `php artisan gaze:doctor` to surface the deprecation warning if you
  missed any.

### Additive

- **Versioned recognizer IDs on audit rows.** `Audit\QueryBuilder`
  returns the new `recognizer_id` + `recognizer_version_id` columns
  verbatim — no code change needed. See
  [`docs/upstream-coverage.md`](../reference/upstream-coverage.md) for the column
  semantics.
- **`PolicySchemaUnsupported` typed exception.** Already shipped in
  v0.7.0; v0.8 binaries are the first to emit it for schemas the
  adapter does not know.

### Deferred to v0.8.x

- **`gaze proxy` daemon mode** is not yet exposed via Artisan. Adopters
  who want the proxy today can run `gaze proxy start` against the
  installed binary; Laravel-side wrappers (config block + 5 artisan
  commands) land in the next adapter minor.
- **`Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds** are still
  deferred from v0.7.x; tracked for v0.8.x adapter release.

## v0.6.x → v0.7.0

`gaze-laravel` 0.7.0 pinned the upstream `CertaMesh/gaze` binary at
**v0.7.2** (previously v0.6.6). Adopters who never override
`GAZE_VERSION` silently jumped across the upstream 0.6 → 0.7 boundary
on `composer update`.

- **Existing `policy.toml` files keep loading.** Upstream v0.7.2
  introduces a top-level `schema_version` field but soft-defaults
  missing values to `0.1.0`, so 0.6.x policies stay drop-in. Pin
  explicitly with `schema_version = "0.1"` at the top of `policy.toml`
  once you want the schema-drift gate to fail closed on future contract
  breaks.
- **New typed exception `GazePolicySchemaUnsupportedException`** fires
  when the binary rejects a policy whose `major.minor` prefix does not
  match its supported schema. The exception carries `found()` /
  `supported()` accessors and shares the `exit=2` bucket with other
  config errors.
- **Existing `GazePolicyConfigDetailException` now exposes `detail()`** —
  upstream threads loader-cause strings (e.g.
  `"unknown bundled rulepack: garbage"`) through the additive `detail`
  sidecar on `{"error":"PolicyConfig","exit":2}`. Code that already
  catches this class needs no change; new code can read `$e->detail()`
  directly.
- **MCP + document subcommands are deferred.** Upstream `gaze mcp
  install` / `gaze document clean` and new validator kinds (`Ipv4Parse`,
  `Ipv6Parse`, `EthEip55`) are tracked as separate follow-up surfaces
  and intentionally not yet exposed through artisan commands or facade
  methods.

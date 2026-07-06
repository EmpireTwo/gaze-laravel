# Changelog

All notable changes to `certamesh/gaze-laravel` (formerly `empiretwo/gaze-laravel`, originally `naoray/gaze-laravel`) will be documented in this file.

## [Unreleased]

### Changed

- **Binary pin bumped `0.11.3` → `0.12.0`** (upstream released 2026-07-06).
  Pure pin-forward: every `gaze --help` / subcommand contract is byte-identical
  to 0.11.3, so no adapter surface changes. What the new binary brings:
  `gaze clean` now warns on stderr when a policy leaves a collision-family
  fallback class uncovered (upstream #360 / PR #362 — the systemic guard behind
  our #141 IBAN leak). The warning is unreachable with the shipped
  `resources/policy.toml` (it covers the family class since #151) and fires
  only on the success path, which the adapter's stderr handling never reads.
  Verified against the real sha256-pinned 0.12.0 binary: full suite green,
  version contract snapshot updated. See
  [docs/reference/upstream-coverage.md](docs/reference/upstream-coverage.md)
  for the delta adjudication.

### Changed (BREAKING)

- **`Gaze::__construct` now takes a `GazeOptions` value object.** The 29-scalar
  constructor is gone; the signature is now
  `new Gaze(BinaryResolver $resolver, ProcessFactory $process, Container $container, ?string $policyPath = null, GazeOptions $options = new GazeOptions)`.
  Every CLI tuning knob (`maxBytes`, `locale`, the safety-net / OPF / Kiji
  family, `restoreMode`, `nerThreshold`, …) moved onto the new readonly
  `CertaMesh\Gaze\GazeOptions` DTO, and `GazeOptions::fromConfig(config('gaze'))`
  is the single config-array → typed-value coercion layer (previously duplicated
  between the provider binding closure and inline casts in the config file).
  This only affects code constructing `Gaze` manually — resolving via the
  container, the facade, or `Contracts\Gaze` is unchanged, and the
  `Contracts\Gaze` public API is untouched. The `clean` argv order the binary
  receives is byte-for-byte identical (contract-pinned by the argv tests).
  Pre-1.0, so this lands on a MINOR bump.

- **`GazeServiceProvider` is no longer a `DeferrableProvider`** and its
  `provides()` method is gone. The bindings are cheap closures (nothing is
  constructed until first resolution), so deferral bought nothing — and it
  caused the known gotcha where `config('gaze.*')` read as `null` in HTTP
  requests that never resolved a gaze service, because the package config was
  only merged once a deferred binding was touched. Registration now always
  runs. **Migration:** none for normal apps; if you called
  `$provider->provides()` directly (unlikely), drop the call.

### Changed

- The shipped `config/gaze.php` groups the ~14 flat safety-net / OPF / Kiji
  root keys into one nested `'safety_net' => [...]` group (with
  `openai_filter` and `kiji` sub-groups). **Not breaking:** env var names are
  unchanged; `GazeOptions::fromConfig()` reads the nested keys first and falls
  back to the deprecated flat keys, so pre-v0.13 published configs keep
  working; and at registration the provider back-fills the flat keys from the
  nested group (collapsing `gaze.safety_net` back to the bool enable switch)
  so legacy `config('gaze.safety_net_*')` readers — including
  `gaze:daemon:serve` and `gaze:doctor` — observe the same values. The flat
  keys are deprecated and will be dropped no earlier than v1.0. The config
  file no longer carries inline casts — coercion lives solely in
  `GazeOptions::fromConfig()`. See UPGRADING.md for the full key map.

- `EncryptedBlob` no longer service-locates `app('gaze.encrypter')` on the hot
  path: `Gaze` now receives the `gaze.encrypter` binding via its constructor
  and passes it to `EncryptedBlob::wrap()`, which accepts an optional encrypter
  parameter (omitting it keeps the container fallback for fakes and fixtures,
  so `wrap($blob)` call sites are unaffected). `fromCiphertext()` stays pure.
  The blob also gains explicit `__serialize`/`__unserialize`: only the
  ciphertext crosses serialization — the encrypter reference is dropped so
  queue payloads never embed the raw encryption key — and decryption after a
  round-trip lazily re-resolves the bound encrypter. Payloads serialized by
  earlier package versions still unserialize (legacy property-shape fallback).

### Changed

- `GazeException::$stderrHash` is now nullable (`?string`). `null` means no
  subprocess stderr stream ever existed — pre-flight validation failures,
  timeouts, stdout decode failures, daemon envelope errors, and the
  `Install\Ner*` family — replacing the previous convention of presenting
  `hash('sha256', '')` (`e3b0c44...`) as a forensic hash of stderr that never
  was. Exception messages render the null case as `stderr_sha256=none`;
  `toLogContext()` keeps the `stderr_sha256` key with a `null` value. A real
  subprocess failure that emitted nothing on stderr still carries the hash of
  the empty string, so "no stream" and "empty stream" are now distinguishable.
  If you type-hinted `$e->stderrHash` as `string`, treat it as `?string`.
- Internal: `Gaze::buildException()` no longer hand-builds each typed
  exception. Construction lives on the wire enum as
  `Variant::toException(string $stage, int $exitCode, ?string $stderrHash, string $stderr = '')`,
  backed by a one-line-per-variant `[exception class, message summary]` table,
  so a new upstream error variant is a one-line addition. Message strings are
  unchanged, and raw stderr still never reaches messages or log context.
- Console-layer dedupe (no behavior change; argv assembly and command output
  are pinned byte-identical by the existing tests):
  - The `appendFlag()` / `stringOption()` / `configString()` /
    `configNumericString()` argv helpers, previously duplicated across the
    proxy, daemon and install command families, now live in one shared
    `CertaMesh\Gaze\Console\Concerns\BuildsBinaryArgv` trait.
  - `ProxyCommand` gained shared `launchFlags()` / `stopFlags()` assemblies
    (deduping `serve`/`start` and `stop`/`restart`) plus a `successExitCode()`
    hook so `gaze:proxy:status` no longer re-implements `runProcess()` just to
    remap the final exit code.
  - `gaze:check` and `gaze:doctor` now share their binary / version / encrypter
    probes via `CertaMesh\Gaze\Console\Concerns\RunsHealthProbes` (check was a
    strict subset of doctor); output text is unchanged.
  - `InstallNerCommand` moved from `CertaMesh\Gaze\Console` to
    `CertaMesh\Gaze\Console\Install` alongside the other install commands.
    The `gaze:install:ner` signature and the deprecated `gaze:install-ner`
    alias are unchanged, but the FQCN change is technically breaking for
    anyone referencing the command class directly.
  - `gaze:install:safety-net` returns `Command::INVALID` instead of a bare
    `2` for an unknown backend (same exit code).

### Deprecated

- The `Queue\Contracts\RequiresFreshClean` marker interface. The
  `GazeIntegrityException::requiresFreshClean()` method is the canonical
  fresh-clean signal — it lives on the exception (mirroring the
  `HasRetryDisposition` method-based pattern) and can give variant-dependent
  answers, which a static marker cannot. Branch on
  `$e instanceof GazeIntegrityException && $e->requiresFreshClean()` instead of
  `$e instanceof RequiresFreshClean`. `GazeBlobExpiredException` and
  `GazeInvalidBlobVersionException` keep implementing the marker until 1.0, so
  existing `instanceof` checks continue to work; nothing in this package ever
  dispatched on it (`GazeRetryPolicy` does not consult it). Resolves
  architecture debt L-3 (two paths for the same semantic).

### Added

- `php artisan about` now carries a `Gaze` section: resolved binary path (or
  `<not installed>`), the package's binary pin, the policy path, and the
  safety-net switch. The section is a lazy closure — nothing is evaluated
  unless `about` actually runs, and it deliberately reports the *pinned*
  version instead of spawning `gaze --version` (doctor owns the real-binary
  probe). Guarded by `class_exists(AboutCommand::class)`.
- Fluent `Gaze::fake()` configuration, mirroring the `Http::fake()` idiom:
  `cleanUsing()`, `maskUsing()` (net-new seam — `mask()` previously had no
  handler), `restoreUsing()`, `auditPurgeUsing()`, `auditExportUsing()`, and
  `daemonCleanUsing()` script each verb by chaining off the returned fake;
  every positional constructor closure now has a fluent twin. Additive — the
  positional-closure constructor keeps working, and a fluent call simply
  replaces the corresponding handler.
- `Gaze::fake()->failWith(GazeException $e)` simulates a broken binary:
  `clean()`, `mask()`, `restore()`, and `daemon()->clean()` throw the given
  exception *after* recording the call, so failure-path tests can still assert
  the service was reached. Takes precedence over scripted handlers.
- `Gaze::fake()->withAuditRows()` / `->withSafetyNetRows()` seed the rows that
  `audit()->query()->execute()` and `audit()->safetyNetQuery()->execute()`
  return (previously unreachable: the fake builders accepted rows but
  `FakeAuditService` always constructed them empty). Rows use the real
  builders' TSV positional-list shape; filters remain no-op recorders.
- `FakeTokenizer`'s token grammar is now composed from named, per-branch
  commented alternation parts (behaviour byte-identical, pinned by a
  per-branch parity dataset).

- `gaze:doctor` now flags a stale pinned binary (north-star P7, "doctor before
  failure"). It parses the installed binary's `--version` and compares it against
  `BinaryDownloader::PINNED_VERSION`; on a mismatch it adds a `pinned version`
  detail row and warns with the exact fix, `php artisan gaze:install --force`. It
  **warns, never fails** — the exit code is unchanged, matching the proxy /
  daemon / restore-telemetry probes — and softens to an "expected" message
  (dropping the `--force` hint) when the adopter has deliberately opted out of
  the pin via `GAZE_BINARY` (a source-built binary) or `GAZE_VERSION` (a
  self-pinned version). Net-new doctor probe → MINOR. Closes the
  plugin-removal gap where a pin bump left the old binary in place with no signal
  until a runtime feature went missing. The version-token parsing is now a shared
  `BinaryDownloader::parseVersion()` so the install-skip decision and this probe
  agree on what "the installed version" is.
- `CertaMesh\Gaze\Daemon\DaemonArgv` — the single source of truth for the
  `gaze daemon` config→flag mapping. Both spawn paths (`gaze:daemon:serve` and
  the `Gaze::daemon()` facade `DaemonClient` binding) now delegate to it, so
  the artisan and facade argv assemblies cannot drift. Null/empty config keys
  still omit the flag so upstream defaults apply.

### Fixed

- **The published `resources/policy.toml` no longer leaks symbol-currency
  amounts (`5000€`, `$3,500.00`)** (#141). The `money_amount` pattern wrapped
  its whole alternation in `\b...\b`; `\b` next to a non-word character
  (`€`/`$`/`£`) only matches when flanked by a word character, so the
  symbol-form branches **never matched in any gaze version** — upstream
  forensics (CertaMesh/gaze#361, byte-identical outputs v0.5.2 vs v0.11.x)
  ruled out a regression, meaning this policy leaked symbol amounts since day
  one. `\b` now guards only the digit/alpha edges (upstream's documented
  boundary-group rewrite); symbols delimit themselves. The four previously
  skipped symbol-amount cases are live again, including both mis-span
  fixtures, and the alpha-code half (`EUR`/`USD`/`GBP`) is pinned unchanged.
  Adopters with a published policy should port the same pattern — see
  UPGRADING.md.
- **The published `resources/policy.toml` no longer leaks IBANs against the
  pinned binary v0.11.3** (#141). Upstream ≥ 0.11.x emits IBAN/credit-card
  spans whose collision cannot be resolved under the fail-closed family class
  `custom:family:payment-card-or-iban` instead of `custom:iban` /
  `custom:credit_card`; the policy had no rule for it, and the `preserve`
  default let IBANs through unredacted. A `tokenize` rule for the family class
  closes the leak (upstream contract question tracked in CertaMesh/gaze#360).
  Adopters with a published policy should add the same rule — see UPGRADING.md.
- The `Gaze::daemon()` facade spawn path now forwards the **full** daemon flag
  surface. Previously it assembled only `--policy`, `--idle-timeout`, and
  `--audit-db`, silently dropping configured `gaze.daemon.session_idle_timeout_s`,
  `session_cap`, `ner_model_dir`, `ner_locale`, `kiji_distilbert_locales`, and
  the shared pipeline keys (`gaze.locale`, `gaze.ner_threshold`, and the whole
  safety-net / OpenAI-filter / Kiji family) when the daemon was spawned via the
  facade instead of `gaze:daemon:serve`.

### Changed

- `EncryptedBlob::fromCiphertext()` is now documented as **supported public
  API** — the rehydration point for adopters persisting a session's
  already-encrypted blob across requests (persist
  `$session->ciphertext->ciphertext()`, rebuild later with
  `EncryptedBlob::fromCiphertext($stored)`). Behaviour is unchanged; a feature
  test now pins the persisted-ciphertext round-trip.

### Changed (BREAKING)

- `SafetyNetConfiguratorResult::$status` is now the backed enum
  `CertaMesh\Gaze\Install\SafetyNetConfigStatus: string { Written = 'written';
  Unchanged = 'unchanged' }` instead of a bare string. The docblocked
  `previewed` status is gone from the surface — it was never emitted
  (`--print` never constructs a result). **Migration:** compare against
  `SafetyNetConfigStatus::Written` / `::Unchanged` (or `->status->value` for
  the old strings). Pre-1.0 breaking-on-MINOR per SemVer policy.
- `BinaryInstaller` is slimmed to its real post-plugin surface:
  `postInstall(Event)` (the documented opt-in `post-install-cmd` /
  `post-update-cmd` script hook), `PINNED_VERSION`, and the
  Composer-trust-context `resolveReleaseBase()`. The ~11 `@internal`
  delegating static shims (`detectTarget`, `alreadyInstalled`,
  `verifyChecksum`, `extract`, `installBinary`, `deriveGithubRepo`,
  `buildRequestHeaders`, `extractAssetIds`, `parseStatusAndLocation`,
  `stripAuthOnCrossHost`, `resolveLocation`) that existed for the removed
  Composer plugin's call sites are deleted; `install()` and
  `isProductionEnvironment()` are now private. **Migration:** call the same
  methods on `BinaryDownloader`, where the pipeline actually lives.

### Removed

- `BinaryDownloader::extract()` and the `.tar.gz` branch of `installBinary()`.
  The pipeline names assets `gaze-{target}` (never an archive), so the branch
  was unreachable; the pinned upstream release ships raw binaries plus
  `.sha256` sidecars only. The now-unused `ext-phar` platform requirement is
  dropped from `composer.json`.
- `NerManifest::fromFile()` — zero callers; manifests only ever arrive via
  `fromUrl()` / `fromString()`.
- `resources/ner/policy-snippet.davlan-mbert.toml` — orphaned since
  `PolicyTomlPatcher` began generating the `[ner]` block programmatically;
  never listed in `NerManifest::FILES`, never copied by the fetcher.

### Removed (BREAKING)

- The Composer plugin (`CertaMesh\Gaze\Install\GazeInstallerPlugin`) is removed
  and the package `type` reverts from `composer-plugin` to `library`. The binary
  no longer auto-downloads on `composer install` / `composer update`;
  `php artisan gaze:install` (or the `gaze:install:binary` sub-command) is now
  the only provisioning path — explicit over magic, and no `allow-plugins`
  friction on first install. **Migration:** delete the now-inert
  `config.allow-plugins."certamesh/gaze-laravel"` key from your app's
  `composer.json` and run `php artisan gaze:install` after upgrading. Binary pin
  bumps now require an explicit `gaze:install --force`; `gaze:doctor` now warns
  (with a `gaze:install --force` hint) when the installed version lags the pin
  but still does not fail on it. Adopters who want the old
  auto-download behaviour can wire `CertaMesh\Gaze\Install\BinaryInstaller::postInstall`
  into a Composer `post-update-cmd` script (honours `GAZE_SKIP_BINARY_DOWNLOAD`).
  `BinaryDownloader` and everything `gaze:install` uses are unchanged — only
  the plugin trigger is gone (but see *Changed (BREAKING)* above:
  `BinaryInstaller`'s plugin-era delegating shims are also removed this
  release). Removed the `composer-plugin-api`
  requirement and the `extra.class` plugin declaration. See
  [UPGRADING.md](UPGRADING.md) (`v0.12.0 → v0.13.0`) for the full guide.

### Fixed

- Tests: the integration behavior pins (`PublishedPolicyTest`, `PolicyRegexBoundaryTest`)
  no longer perma-skip on a stale `0.5.x` version sniff — they now gate on
  `BinaryDownloader::parseVersion() >= PINNED_VERSION` and pin actual v0.11.3
  behavior — and `ServiceProviderTest` no longer reddens the suite when
  `GAZE_BINARY` is exported (the documented way to run the integration tests).
  Un-skipping surfaced two published-policy gaps against the pinned binary
  (IBANs and symbol-currency amounts pass through unredacted); tracked in
  [#141](https://github.com/CertaMesh/gaze-laravel/issues/141) with the
  affected cases skipped-with-TODO, not papered over.

## [0.12.0] - 2026-07-03

### Changed (BREAKING)

- `GazeSafetyNetFailureException` no longer implements the `NonRetryable`,
  `Retryable`, and `RetryableWithAlert` marker interfaces. It previously
  implemented **all three at once** (the truth lived in its variant-driven
  `is*()` methods), so any adopter branching on `$e instanceof NonRetryable`
  misclassified retryable safety-net variants (`Timeout`, `Other`) as terminal.
  It now implements the new `CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition`
  contract (`retryDisposition(): RetryAction`), which
  `GazeRetryPolicy::classify()` consults generically before the marker
  interfaces — the class-specific special case in the policy is gone. Unknown
  upstream variants continue to fail closed (`RetryAction::Fail`); every
  documented variant classifies exactly as before. **Migration:** replace
  `$e instanceof NonRetryable/Retryable/RetryableWithAlert` checks against this
  exception with `GazeRetryPolicy::classify($e)` or, in hand-rolled chains, an
  `$e instanceof HasRetryDisposition` arm (checked first). The `is*()` helper
  methods and `safetyNetVariant()` are unchanged. Pre-1.0 break; this contract
  freezes at 1.0.

- Root namespace renamed `Naoray\GazeLaravel` → `CertaMesh\Gaze`. Migration:
  replace `use Naoray\GazeLaravel\…;` with `use CertaMesh\Gaze\…;`. The `Gaze`
  facade alias is unchanged.
- Composer package renamed `empiretwo/gaze-laravel` → `certamesh/gaze-laravel`
  (CertaMesh is the canonical project identity). Migration:
  `composer remove empiretwo/gaze-laravel && composer require certamesh/gaze-laravel`,
  and update the `config.allow-plugins` key in your `composer.json` from
  `empiretwo/gaze-laravel` to `certamesh/gaze-laravel` so the binary-installer
  plugin keeps running. See [UPGRADING.md](UPGRADING.md) for the full guide.

### Changed

- Bump the pinned upstream `gaze` binary from `0.11.2` to `0.11.3`. Pure
  supply-chain + correctness pin-forward — no new adapter surface, no new flag,
  no wire/default change. Verified against the real 0.11.3 binary: every
  `gaze --help` / subcommand-help contract snapshot is byte-identical to
  `0.11.2`. Upstream 0.11.3 ships supply-chain hygiene (pdfium build-input pin,
  dead `daemonize` dependency dropped), observer-safety-net leak fixes, `restore`
  token-ordinal parsing tightened to ASCII digits only, and property-test infra.
  The restore-ordinal fix is a correctness hardening for malformed token input
  and is not observable through the package's clean/restore round trip
  (reversibility unchanged, NORTH_STAR §4). See `docs/how-to/upgrading.md`
  (`v0.11.2 → v0.11.3`) and the new deltas table in
  `docs/reference/upstream-coverage.md`.
- Bump the pinned upstream `gaze` binary from `0.11.1` to `0.11.2`. Adopters
  get the new default recognizers for free by taking the pin: EU VAT IDs,
  ISO-length-gated IBANs, and spaced international E.164 phone numbers. The
  pin also picks up the NER loader fix for the Kiji bundle (relevant to the
  `kiji-distilbert` safety-net backend) and upstream proxy/email-TLD
  recognizer hardening. No adapter surface change; see
  `docs/how-to/upgrading.md` (`v0.11.1 → v0.11.2`) and the re-adjudicated
  TokenBridge entry in `docs/reference/upstream-coverage.md`.
- `BinaryDownloader::alreadyInstalled()` now compares the semver token
  extracted from `gaze --version` output **exactly** (`===`) instead of via
  substring match — previously an installed `0.11.10` would have satisfied a
  `0.11.1` pin and skipped the download.
- CI derives `GAZE_VERSION` from `BinaryDownloader::PINNED_VERSION` after
  `composer install` instead of hardcoding the version in the workflow.

### Added

- Audit parity with upstream 0.11.x (MINOR). Four surfaces, all pure argv
  forwarding — no PHP-side filtering:
  - **Query filters:** `Audit\QueryBuilder` grows fluent methods for every
    upstream `gaze audit query` filter flag — `whereClass()` (`--class` is a
    PHP reserved word; the `where` prefix is kept consistent for the other
    value filters), `whereSource()`, `whereAction()`, `whereDocumentKind()`,
    `from()`/`to()` (Carbon → ISO 8601 UTC Zulu), `whereSession()`,
    `hasAmbiguity()`, `whereAmbiguityReason()`, `whereCollisionFamily()`,
    `whereCollisionVariant()`, alongside the existing `onlyRestoreEvents()`.
    Flags assemble in upstream `--help` order for deterministic argv.
  - **Export:** `QueryBuilder::export(?string $output = null, string $format = 'jsonl')`
    runs `gaze audit export`, reusing the accumulated filter state (upstream
    applies the identical filter flags to both subcommands). Returns a new
    `Audit\AuditExportResult` (`format`, `path`, `rawOutput`, plus
    `rowCount()`/`rows()` derived from captured stdout JSONL — upstream
    reports no count of its own and only accepts `jsonl` in 0.11.x; the
    format is forwarded verbatim).
  - **Safety-net query:** `Gaze::audit()->safetyNetQuery()` wraps
    `gaze audit safety-net query` (flattened name — `query` is upstream's
    only `safety-net` subcommand) via the new
    `Contracts\SafetyNetQueryBuilder` / `Audit\SafetyNetQueryBuilder` with
    `whereLeakKind()`, `whereRawLabel()`, `whereMappedClass()`,
    `whereFieldPath()`, `from()`/`to()`. Help snapshots pinned for both new
    subcommands.
  - **`php artisan gaze:audit:purge`:** scheduler-friendly wrapper around the
    purge builder — `--before=` (ISO 8601 or Carbon-parseable relative
    expression, normalised to UTC Zulu), `--audit-db=` per-run override,
    `--dry-run`, and `--force` to bypass the interactive confirmation on the
    destructive path. Exit codes SUCCESS/FAILURE/INVALID.

  Upstream `gaze audit purge` stdout is now pinned
  (`{"dry_run":bool,"matched":N,"deleted":N}`, verified against the real
  0.11.1 binary — the previous `"N rows"` regex never matched it, so
  `AuditPurgeResult::$count` was always null): `AuditPurgeResult` additively
  gains `$matched`/`$deleted`, and `$count` is now populated (`matched` on
  dry runs, `deleted` on real runs). Query/safety-net rows keep the thin TSV
  contract — positional `list<list<string>>` whose first row is upstream's
  header line; string-keyed access goes through `export()` JSONL.

  Testing surface: fake builders record `appliedFilters()` keyed by upstream
  flag name; `FakeAuditService` records `exportCalls()`/`safetyNetQueryCalls()`;
  `Gaze::fake()` accepts an `auditExportHandler`;
  `Gaze::assertAuditExported(?string $path)` added and
  `Gaze::assertNothingAudited()` now also fails on export/safety-net calls.
  The `@internal` `Contracts\AuditRunner` grows `runForAuditExport()` and
  `runForAuditSafetyNetQuery()`.
- Service contracts under `CertaMesh\Gaze\Contracts`: `Gaze`, `AuditService`,
  `PurgeBuilder`, `QueryBuilder`, `DaemonManager`, `DaemonSession` (full public
  API of each service), plus the `@internal` `AuditRunner` carrying the
  audit-scoped process runners (`runForAuditPurge`/`runForAuditQuery`) so they
  stay out of the public `Contracts\Gaze` surface. Concrete services implement
  their contracts; the container binds the contracts canonically and aliases the
  concrete FQCNs to them, so `app(Gaze::class)` and
  `app(Contracts\Gaze::class)` resolve the same singleton. The `Gaze` facade
  accessor now resolves `Contracts\Gaze`, and `Gaze::fake()` swaps the contract
  binding (concrete-name resolution follows via the alias). Type-hint the
  contracts, not the concretes. Value objects (`GazeSession`, `EncryptedBlob`,
  `Entry`, `CleanResponse`, `LeakReport`) intentionally remain concrete classes
  with no interfaces. `FakeQueryBuilder` gains
  `wasRestrictedToRestoreEvents(): bool` for assertions.
- Full upstream flag parity for `gaze:daemon:serve` (MINOR). The command now
  forwards **every** flag the pinned gaze v0.11.1 `daemon` subcommand accepts
  (previously only `--policy` / `--idle-timeout` / `--audit-db`), so daemons
  with safety-net or NER overrides can be launched via artisan. New artisan
  options (override config): `--session-idle-timeout`, `--session-cap`,
  `--locale`, `--ner-threshold`. New `gaze.daemon.*` config keys
  (`GAZE_DAEMON_*` env): `session_idle_timeout_s`, `session_cap`,
  `ner_model_dir`, `ner_locale`, `kiji_distilbert_locales`. The shared
  pipeline flags — `--locale`, `--ner-threshold`, and the full safety-net /
  OpenAI-filter / Kiji family — source the same top-level `gaze.*` keys the
  one-shot `Gaze::clean()` path forwards, so a configured pipeline behaves
  identically in both runtimes; artifact paths stay config-only, mirroring
  the one-shot posture. Pure flag forwarding — no PHP-side logic. See
  `docs/reference/upstream-coverage.md#daemon-flags`.
- Clean `leak_report` surfaced as a `GazeSession` trust state (MINOR + trust fix).
  `Gaze::clean()` previously **dropped** the upstream `leak_report` — the
  pipeline's own coverage check — leaving callers to infer safety from the
  detection count, which over-asserts (a high count never proves a span did not
  bleed through). The report is now parsed into a typed, metadata-only
  `CertaMesh\Gaze\LeakReport` (+ `LeakSuspect`) attached to `GazeSession`, with
  `GazeSession::coverageState(): CoverageState` (`Verified` | `Unverified` |
  `Suspect`) and `GazeSession::hasSuspectedLeak(): bool`. A `null`/absent report
  degrades to `Unverified`, never `Verified`. `LeakReport`/`LeakSuspect` read a
  strict field allowlist (no source text, no byte offsets ever carried — a
  hostile-fixture test enforces it). Additive `?LeakReport $leakReport` field on
  `GazeSession`; no detection logic in PHP — the binary's report is only
  surfaced. **Caveat:** the `Suspect` (red) state depends on the observer-only
  Pass-3 safety net, a compile-time feature absent from the stock release binary,
  so through the stock CLI the strongest reachable state is `Unverified` (the
  four coverage-gap counts are always present). See
  `docs/reference/upstream-coverage.md` and `docs/explanation/security.md`.
- Per-call NER threshold override (MINOR). `Gaze::clean(string $text, ?float $threshold = null)`
  accepts an optional threshold and forwards it to `gaze clean` as
  `--ner-threshold=<value>` (upstream: "Override policy [ner] threshold. Must be
  between 0.0 and 1.0 inclusive"). New config key `gaze.ner_threshold` /
  `GAZE_NER_THRESHOLD` provides the default; the per-call argument wins over it.
  Effective values are validated to the inclusive `0.0`–`1.0` range
  (`InvalidArgumentException` otherwise); null at both levels omits the flag and
  lets upstream apply the policy's own threshold. Pure flag forwarding — no
  detection logic in PHP.
- `Gaze::mask(string $text, ?callable $replace = null): string` one-way redaction
  helper (MINOR). Runs the existing `clean()` detection path, then replaces each
  detected token in the clean text with a masked label — `[<class>]` by default,
  or the return of a `callable(Entry): string`. UNLIKE `clean()`/`restore()`,
  `mask()` is NON-reversible: the encrypted session blob is discarded and there
  is no restore counterpart. Reshapes `clean()`'s existing inventory only; adds
  no detection of its own. `Gaze::fake()` mirrors it (`maskCalls()` recorder).
  Implemented on the collision-safe token map — per-detection byte offsets remain
  a deferred upstream feature request (see `docs/reference/upstream-coverage.md`).
- `php artisan gaze:install` umbrella command (MINOR). Provisions a Laravel app
  to use gaze end-to-end — binary, config, policy, NER model, safety-net backend
  — in one idempotent pass, ending on a `gaze:doctor` gate and a per-step summary
  table. Interactive prompts plus non-interactive flags: `--no-interaction`,
  `--force`, `--force-policy`, `--skip-binary`, `--skip-ner`, `--skip-safety-net`,
  `--safety-net=opf|kiji|none`, `--kiji-model-dir=`, `--ner-variant=`,
  `--ner-locale=`, `--no-doctor`. The final gate runs `gaze:doctor` as a real
  subprocess so it reflects the freshly written `.env`; an existing `policy.toml`
  is never clobbered without `--force-policy`; a failed run rolls `.env` back to
  its pre-install state.
- `gaze:install:binary` (MINOR). Installs the pinned gaze binary from artisan
  (not only via the Composer plugin); `--force` re-downloads. Defers to any
  binary that already resolves (`GAZE_BINARY`, `vendor/bin`, or PATH) and treats
  an unsupported platform as a skip-with-guidance success when a binary otherwise
  resolves, so adopters who build from source are never hard-failed.
- `gaze:install:safety-net` (MINOR). Wires the OPF (openai-filter) or Kiji
  DistilBERT backend into `.env` idempotently; `--safety-net=`, `--kiji-model-dir=`,
  `--opf-command=`, `--opf-checkpoint=`, `--force`, `--print`. The Kiji model dir
  is validated against the pinned artifact set BEFORE `.env` is written (the same
  contract `gaze:doctor` enforces), so a write never leaves doctor red. OPF is a
  local subprocess: its command/checkpoint paths are wired and the command warns
  that doctor cannot verify the subprocess. `--print` redacts secret-shaped keys.
  Kiji model artifacts remain upstream-provided (doctor validates the dir).
- Laravel 13 support (MINOR). `^13.0` added to all `illuminate/*` constraints;
  `orchestra/testbench` widened to `^11.0`; `symfony/http-client` widened to
  `^7.0 || ^8.0` (matching `symfony/process`). PHP requirement stays `^8.2` so
  Laravel 11/12 adopters on PHP 8.2 are unaffected; because Laravel 13 requires
  PHP >= 8.3, the CI matrix excludes the PHP 8.2 + Laravel ^13.0 combination and
  adds PHP 8.4.
- Restore-decision telemetry surface (MINOR). Opt-in, forwards-only — adds no
  detection or PII logic in PHP:
  - Config key `gaze.restore_telemetry` (env `GAZE_RESTORE_TELEMETRY`), default
    `null` (telemetry off = upstream default).
  - `Gaze::restore()` forwards `--telemetry` when enabled, plus
    `--audit-db=<gaze.audit_db_path>` when that path is set (telemetry with no
    audit-db path still forwards `--telemetry` so the binary uses its default
    sink). Wire shape is byte-identical when the key is null/false.
  - `CertaMesh\Gaze\Audit\QueryBuilder::onlyRestoreEvents()` — fluent filter
    forwarding `--restore-events` to `gaze audit query`.
  - `gaze:doctor` probe for restore-telemetry audit-db writability: skipped
    silently when off; warns (never hard-fails) when on but `gaze.audit_db_path`
    is unset or its parent dir is not writable.

  CAVEAT: two upstream audit columns — `restore_fresh_pii_count` and
  `restore_manifest_bypass_count` — are ALWAYS 0 through the stock gaze CLI,
  because gaze-cli's `run_restore` never enables the Phase-B DLP builder. This
  surface ships for restore-decision and unknown-token audit trails, NOT for
  outbound-DLP fresh-PII detection. Do not rely on it for DLP.
- `Gaze::assertMasked(?string $expectedText = null)`,
  `Gaze::assertMaskCount(int $expected)` and `Gaze::assertNothingMasked()`
  facade assertions (MINOR). `mask()` calls were already recorded by
  `FakeGaze::maskCalls()` but had no assertion helpers, unlike every other
  recorded verb. Signatures and failure messages mirror the existing
  `assertCleaned` / `assertCleanCount` / `assertNothingCleaned` family.

### Changed

- **Potentially BREAKING for tests:** the testing fakes (`FakeGaze`,
  `FakeAuditService`, `FakeDaemonManager`, `FakeDaemonSession`,
  `FakePurgeBuilder`, `FakeQueryBuilder`) now implement the new
  `CertaMesh\Gaze\Contracts\*` interfaces directly and no longer extend the
  concrete service classes (previously they bypassed the parent constructors,
  leaving dozens of readonly promoted properties uninitialized — any inherited
  method not overridden fataled with an uninitialized-typed-property `Error`).
  If your code type-hints a concrete service (e.g. `Gaze $gaze`,
  `Daemon\DaemonManager`) and you pass it a fake in tests, switch the hint to
  the corresponding contract. Fake call-recording behavior (`cleanCalls()`,
  `maskCalls()`, purge/daemon assertions) is unchanged;
  `FakeDaemonManager::client()` now throws an explicit `LogicException`
  instead of fataling.
- Replaced the unmaintained `yosymfony/toml` (^1.0, last release 2018, TOML
  spec 0.4) with `devium/toml` (TOML 1.0, PHP >=8.2, actively maintained).
  Upstream `gaze` writes TOML 1.0 policy files, so this is a correctness
  improvement: TOML 1.0 constructs like dotted keys and heterogeneous arrays
  in a `policy.toml` no longer fail PHP-side parsing. Parse failures in
  `PolicyTomlPatcher` still surface as `NerManifestInvalidException`.
  `gaze:doctor` now also emits a warning when `policy.toml` cannot be parsed
  as TOML (previously the deprecated-rulepack check swallowed the error
  silently).
- `Install\NerInstallException` (and its 7 `Ner*Exception` subclasses) now
  extends `Exceptions\GazeException` instead of `\RuntimeException` directly,
  so `catch (GazeException $e)` is the single catch-all surface for every
  exception this package throws. Catch-behaviour implications:
  - `catch (\RuntimeException)` and `catch (NerInstallException)` keep working
    unchanged (`GazeException` itself extends `\RuntimeException`).
  - `catch (GazeException)` now ALSO catches NER install failures — relevant
    only if you invoke `NerInstaller`/`gaze:install:ner` inside such a block.
  - `getCode()` on NER install exceptions now returns `exitCode()` (previously
    always `0`), and constructing `NerManifestInvalidException`/
    `NerTransportException` with a positional `int $code` second argument is no
    longer possible — the base constructor is now
    `(string $message = '', ?\Throwable $previous = null)`; use
    `previous:` as a named argument.
  - Inherited `GazeException` members are populated conservatively:
    `$exitCode` mirrors `exitCode()`, `$stderrHash` is `hash('sha256', '')`
    (no subprocess ran), `$variant` is `null`, `isCallerBug()` is `false`.
    No `Queue\Contracts` retry interfaces are implemented, so
    `GazeRetryPolicy` classification is unaffected. Nothing in the
    `GazeException` constructor requires a Laravel app, so the Composer-plugin
    install path (no app context) is unaffected.
- `gaze:install-ner` renamed to `gaze:install:ner` (MINOR). The old name keeps
  working as a deprecated alias, so existing scripts are unaffected. A new `--yes`
  flag confirms a headless install WITHOUT re-downloading the model or overwriting
  `policy.toml` — distinct from `--force`, which still re-downloads/overwrites.
- Binary download/verify logic extracted from `BinaryInstaller` into a
  framework-agnostic `BinaryDownloader` service (no behaviour change). The
  Composer plugin auto-install path is byte-identical — it still resolves and
  pins the release base in production before delegating, and the per-message
  stdout/stderr channel is preserved. `gaze:install:binary` reuses the same
  single download/checksum path.
- Bump the pinned upstream `gaze` binary from `0.9.0` to `0.11.1`
  (`BinaryInstaller::PINNED_VERSION`, CI `GAZE_VERSION`). Adopts upstream
  NER fail-closed (#290/#293) and byte-exact restore (#295) purely via the
  binary — no adapter logic change, no new flag. Restore wire shape and
  exit-code buckets are unchanged. Aligns the downloaded binary with the
  daemon surface shipped in the v0.11.0 adapter release.
- Raise the `symfony/process` floor from `^7.0` to `^7.1.4` (`|| ^8.0`
  unchanged). Versions below 7.1.4 lack the posix_spawn `proc_open()`
  handling fix, so on PHP >= 8.3 probing a missing binary throws
  `ProcessStartFailedException` instead of yielding a failed result,
  breaking `gaze:install:binary`'s trust check. Exposed by the new
  prefer-lowest CI leg; highest resolution is unaffected.
- Dev tooling: phpstan bumped `^1.11` → `^2.1` (the two 1.x-era ignore
  blocks it obsoletes are deleted; all new 2.x findings fixed at the
  source, no baseline). New `.github/dependabot.yml` (weekly, composer +
  github-actions, minor+patch grouped). CI matrix gains PHP 8.5 legs
  (against Laravel 12/13) and a PHP 8.2 prefer-lowest job that validates
  the declared dependency lower bounds.

### Fixed

- The `PHP 8.2 / prefer-lowest` CI leg pinned a stale `GAZE_VERSION: "0.11.1"`
  literal while `BinaryDownloader::PINNED_VERSION` had advanced to `0.11.2`. The
  desync made `BinaryInstaller::install()` look for a version the test fixtures
  never report, so the "already installed" short-circuit was skipped and two
  `BinaryInstallerTest` cases fell through to the real network download path,
  failing on every push to `main`. The leg now derives `GAZE_VERSION` from
  `PINNED_VERSION` at runtime (mirroring the main test job's single source of
  truth), and `BinaryInstallerTest` now resets the `GAZE_*` / `APP_ENV`
  environment in `beforeEach` so the full-pipeline tests are hermetic regardless
  of ambient exports — which also removes the host-local nondeterministic flake
  in those two cases. No runtime behaviour changed.
- The NER-download HTTP client is now bound as `gaze.http_client` instead of
  hijacking the global `Symfony\Contracts\HttpClient\HttpClientInterface`
  binding; host apps or packages that (perhaps unknowingly) relied on gaze's
  retrying client via the generic interface must now bind their own.
- `DaemonClient` no longer wires the daemon child's stderr to a pipe that
  nothing reads. A chatty daemon could fill the ~64KB kernel pipe buffer,
  block on its next stderr write, and time out every subsequent request.
  When `gaze.daemon.stderr_path` is unset, child stderr now goes to
  `/dev/null`; an explicit path is honoured as before (append mode).
- `DaemonClient::request()` no longer issues an unbounded blocking
  `fwrite()` to daemon stdin. A wedged daemon that stopped draining its
  stdin pipe could hang the PHP worker indefinitely — past
  `request_timeout_ms`. Writes are now non-blocking and
  `stream_select()`-bounded by the same millisecond deadline as reads,
  failing closed with `GazeDaemonTimeoutException` (no payload text in
  the exception).
- `DaemonClient::disconnect()` now escalates SIGTERM → (≈2s grace,
  polling `proc_get_status`) → SIGKILL before `proc_close()`, so a daemon
  that ignores SIGTERM can no longer hang teardown of the Octane worker.
- Integration tests no longer point at the long-deleted `policy.toml.example`
  (moved to `resources/policy.toml` in #55); a missing policy fixture now hard-fails
  instead of silently skipping, and the cross-session test asserts the upstream
  token-isolation fix (`GazeUnknownTokenException`) instead of the legacy rc.3 leak.
- `FakeGaze::clean()` now forwards the per-call `$threshold` to a custom
  `$cleanHandler` (second argument), so handlers can branch on it the way
  the real `Gaze::clean()` forwards `--ner-threshold`. Docblocks widen to
  `\Closure(string, ?float): GazeSession`. BC-safe: PHP user-land closures
  ignore surplus arguments, so existing single-parameter handlers keep
  working unchanged.
- Fake masking parity between the one-shot and daemon paths.
  `FakeDaemonManager` masked only real email addresses while `FakeGaze`
  carried the full multi-class token grammar (despite a comment claiming
  they mirrored each other). Both fakes now delegate to one internal
  `Testing\FakeTokenizer` — the full grammar plus real-email masking — so
  `Gaze::fake()` masks identically via `clean()` and
  `daemon()->session(...)->clean()`. Daemon fake output is now richer
  (class/custom tokens and the `Alice` name fallback are masked, not just
  emails), and `FakeGaze` additionally masks real email addresses to
  `<Email_1>`. The daemon fake also computes `clean_text` once per request
  instead of twice.
- `gaze:daemon:status` process discovery on macOS. The command used the Linux
  procps `pgrep -af` spelling, which on darwin means "include ancestors" and
  emits bare PIDs, so every discovered daemon rendered as `unknown`; darwin now
  uses `pgrep -fl`, the command's own PID is excluded from matches, and the
  unused `$config` parameter was dropped from `handle()`.
- Correct the stale `gaze:doctor` core-extended deprecation notice. It
  claimed "Removal target: v0.10.0", but upstream never removed the pack —
  it still soft-aliases `core-extended` → `core` with a runtime warning
  through v0.11.x. Message now states removal is deferred. Probe severity
  is unchanged (still a warning, not an error).

### Security

- Repoint the pinned upstream `gaze` binary download base and the NER
  `SHA256SUMS.ner` manifest URL from the vacated `EmpireTwo/gaze` GitHub
  org to the canonical `CertaMesh/gaze`. The old name relied on GitHub's
  rename 301-redirect, which is not canonical and becomes a supply-chain
  takeover vector if the handle is re-registered.
  (`BinaryInstaller::RELEASE_BASE`, `GazeServiceProvider` NER fetch.)

### Documentation

- Coverage-table honesty pass: `docs/reference/upstream-coverage.md` now lists
  the previously omitted `gaze clean` runtime NER overrides (`--ner-model-dir`,
  `--ner-locale`) and the v0.9.0 safety-net registry flag family
  (`--safety-net-registry`, `--safety-net-add`, `--opf-locales`,
  `--kiji-distilbert-locales`, plus the `--opf-command`/`--opf-checkpoint`
  aliases) with explicit **defer** verdicts — none of these are exposed by the
  adapter today. Also documents that `gaze.locale` / `GAZE_LOCALE` is forwarded
  verbatim and upstream parses it as a comma-separated priority fallback chain
  (`GAZE_LOCALE=de-DE,en` already works); config docblock and upgrade guide
  updated to match. No behavior change.
- README / getting-started examples no longer pass `$request->string('body')` (an `Illuminate\Support\Stringable`, which fatals against `clean(string $text)` under `strict_types=1`) — they use `$request->input('body')` with a callout explaining the trap; `examples/clean-before-openai.php` (+ `examples/README.md`) now demonstrates the `coverageState()` / `hasSuspectedLeak()` trust gate before text crosses the model boundary.

## [0.11.1] - 2026-05-18

### Changed

- `docs/daemon.md` opener aligned with upstream PR #276 terminology
  reframe (gaze daemon → stdio server in the LSP / MCP tradition).
  Verb, flags, wire shape, error envelopes, and audit-source literals
  are unchanged.

### Fixed

- Stale upstream-spec link in `docs/daemon.md` See-also section
  (`docs/adopter/daemon-quickstart.md` → `docs/getting-started/daemon-adapter.md`).

## [0.11.0] - 2026-05-18

### Added

- `Gaze::daemon()` Facade method exposing the long-lived `gaze daemon`
  JSONL runtime. Two entry shapes: composition fluent sugar
  `Gaze::daemon()->session($id)->clean($text)` and direct hot path
  `Gaze::daemon()->clean($sessionId, $text)` — both return the same
  `CleanResponse` DTO and route through one request-scoped
  `DaemonClient` per Octane request boundary.
- TWO new artisan commands: `php artisan gaze:daemon:serve` (foreground
  wrapper for systemd / Horizon process / supervisord; forwards
  SIGTERM/SIGINT to the child via pcntl handlers) and `php artisan
  gaze:daemon:status` (best-effort `pgrep -af "gaze daemon"`; help text
  carries explicit supervisor-is-source-of-truth caveat).
- Six flat config keys under `gaze.daemon.*`: `policy_path`,
  `audit_db_path`, `request_timeout_ms` (default 5000),
  `idle_timeout_s`, `binary_path`, `stderr_path`. Each backed by a
  `GAZE_DAEMON_*` env var. All default null so the upstream binary
  applies its own defaults; populating a key forwards the matching
  flag. Doctor's daemon section is gated on `policy_path` being set
  — the opt-in signal.
- Exception family rooted at `GazeDaemonException extends
  GazeIntegrityException` carrying `sessionId`, `raw`, and a
  `DaemonErrorVariant` backed enum (cases: `JsonMalformed`, `Pipeline`,
  `Transport`, `Timeout`, `Unavailable`, `Unknown` forward-compat
  sink). Three surface-distinct subclasses ship for adopter catch
  ladders: `GazeDaemonTransportException` (EOF / broken pipe /
  mismatched session id; fail-closed, no auto-reconnect),
  `GazeDaemonTimeoutException` (per-request millisecond deadline),
  `GazeDaemonFeatureUnsupportedException` (binary missing `daemon`
  subverb; surfaces `cargo install gaze-cli --features daemon` hint).
  The family does NOT implement `Retryable` — adopter owns queue
  retry policy.
- Doctor probe extension: `gaze:doctor` pre-flights `gaze daemon
  --help` when `gaze.daemon.policy_path` is set, checks
  policy/audit/stderr path readability, and surfaces the cargo-install
  hint when the binary lacks the subverb.
- `Gaze::fake()` extension: `assertDaemonCleaned()`,
  `assertDaemonCleanCount()`, `assertNothingDaemonCleaned()` mirror
  the existing one-shot assertions. `Gaze::fake()->daemon()` returns a
  `FakeDaemonManager` with `session()` / `clean()` / `calls()` so
  adopter tests can assert daemon usage without spawning a real
  binary.
- `docs/daemon.md` adopter quickstart mirroring `docs/proxy.md`. Top
  callout documents the reversibility boundary (daemon is clean-only;
  `DaemonSession::restore()` does NOT exist).
- `DaemonSession` is intentionally NOT serializable;
  `__serialize()` throws `\LogicException`. Queueing a session would
  hand a worker a stale handle to a daemon it never saw — resolve a
  fresh `Gaze::daemon()->session($id)` per worker tick.

### Changed

### Fixed

## [0.10.0] - 2026-05-16

Upstream `EmpireTwo/gaze` v0.9.0 final adoption release. Advances the
pinned binary from v0.8.1 to v0.9.0 and exposes the new v0.9 Kiji int8
ORT runtime via two adopter-facing config keys (`gaze.kiji_backend` and
`gaze.kiji_distilbert_precision`) that forward `--kiji-backend` and
`--kiji-distilbert-precision` to upstream. CI is pinned to
`GAZE_VERSION=0.9.0`. `gaze daemon` wrapping is intentionally deferred —
v0.9.0 daemon mode is clean-only JSONL and does not surface the signed
session blob required by `Gaze::restore()`, so the current
`GazeSession` reversibility contract (NORTH_STAR Principle 4) would
break. Also lands the new `docs/NORTH_STAR.md` project compass that
codifies the five adopter axes and the surface-promotion rule used to
justify this release's SemVer bump.

MINOR bump (pre-1.0 SemVer per `docs/NORTH_STAR.md`): this release
introduces two net-new adopter config keys plus two new upstream flag
forwards, which qualifies as net-new adopter surface and therefore a
MINOR — not a PATCH.

### Changed

- Bump the pinned upstream `EmpireTwo/gaze` binary from v0.8.1 to v0.9.0
  final. Restore semantics remain on the existing one-shot
  `gaze clean` / `gaze restore` path.
- CI exports `GAZE_VERSION=0.9.0` while continuing to skip automatic
  binary download in wrapper-only jobs.

### Added

- `gaze.kiji_backend` / `GAZE_KIJI_BACKEND` and
  `gaze.kiji_distilbert_precision` /
  `GAZE_KIJI_DISTILBERT_PRECISION` config keys, forwarding
  `--kiji-backend` and `--kiji-distilbert-precision` so adopters can opt
  into v0.9's ORT int8 Kiji path.
- `docs/upgrading.md` documents why `gaze daemon` is not wrapped yet:
  v0.9.0 daemon mode is clean-only JSONL and does not expose the signed
  session blob or restore request type required by the current PHP
  `GazeSession` contract.
- `docs/NORTH_STAR.md` project compass documenting the seven guiding
  principles, the five adopter axes (Reliability, Reversibility,
  Agentic-first, Trust, Adopter ergonomics), the surface-promotion
  rule, and the pre-1.0 SemVer policy used to size this bump.

## [0.9.1] - 2026-05-15

Documentation-only patch. Ships the adopter usage guide for SafetyNet (both backends) that should have shipped alongside v0.9.0.

### Added

- docs/safety-net.md: adopter usage guide for both SafetyNet backends (OPF + Kiji), config reference, mode/fallback semantics, doctor probe, exception handling, security notes.

## [0.9.0] - 2026-05-15

Upstream `EmpireTwo/gaze` v0.8.x SafetyNet reshape adopter release. Pins
binary at v0.8.1, exposes the new Kiji DistilBERT safety-net backend
(Tier 2.5), the four-valued `safety_net_mode` enum, and the typed
`SafetyNetArtifactMissing` envelope through Laravel-native config keys,
exception classes, and a `gaze:doctor` pre-flight.

MINOR bump (pre-1.0 SemVer) reflects the new adopter-facing surface —
four config keys, four argv flags, a typed exception class, a new
`Variant` enum case, a new `DoctorCommand` probe, plus the upstream
default flip of `safety_net_mode` from `strict` to `resolve` (see
`docs/upgrading.md`). Patch framing (v0.8.2) was incorrect: net-new
features under pre-1.0 SemVer warrant MINOR, not PATCH.

### Added

- `config/gaze.php` four new safety-net keys (each with matching
  `GAZE_*` env override): `safety_net_backend`, `kiji_distilbert_command`,
  `kiji_distilbert_model_dir`, `safety_net_fallback`. Each forwards as an
  exact upstream CLI flag; null defers to upstream defaults.
- `Naoray\GazeLaravel\Exceptions\GazeSafetyNetArtifactMissingException`
  (extends `GazePolicyConfigException`, exit 2,
  `Queue\Contracts\NonRetryable`) with `backend()` + `path()` accessors
  for the upstream `{error:"SafetyNetArtifactMissing",backend,path}`
  envelope. New `Variant::SafetyNetArtifactMissing` case routes through
  the exit-2 bucket.
- `DoctorCommand` Kiji artifact pre-flight: when
  `gaze.safety_net_backend === 'kiji-distilbert'`, doctor asserts the
  model dir is set and contains `SHA256SUMS`, `labels.json`,
  `model.onnx`, and `tokenizer.json`. Failures surface the upstream
  `scripts/fetch-kiji-safetynet-model.sh` remediation hint and flip
  doctor status to FAILURE. Silent skip when backend is unset or
  `openai-filter`.
- `docs/upstream-coverage.md` Kiji + safety-net reshape table, error
  variant row, and the doctor pre-flight note.
- `docs/upgrading.md` v0.8.1 → v0.9.0 section explaining the upstream
  default flip + adopter opt-in path for the new Kiji backend.
- README features-list bullet for the Kiji DistilBERT backend.

### Changed

- `config/gaze.php` `safety_net_mode` docblock now enumerates all four
  valid values (`strict|tolerant|redact|resolve`) and notes the upstream
  default flipped from `strict` to `resolve` in v0.8.1. The adapter does
  not pin a default — passing `null` (the config default) lets the
  binary apply its own.

### Notes

- Upstream PR refs (EmpireTwo/gaze): `--safety-net-backend` value-enum
  + Kiji subprocess wiring (#216), `--kiji-distilbert-command` /
  `--kiji-distilbert-model-dir` flag pair (#217), `safety_net_mode` →
  `Redact` / `Resolve` enum + default flip (#221), `--safety-net-fallback`
  value-enum (#223).
- `BinaryInstaller::PINNED_VERSION` remains `0.8.1`. The
  `GAZE_VERSION` env override remains the supported pin escape hatch.

## [0.8.1] - 2026-05-14

Optional gaze-proxy daemon Artisan wrapper. Ships six `php artisan
gaze:proxy:*` commands that mirror upstream's `gaze proxy {serve, start,
stop, status, logs, restart}` surface, a `config/gaze.php` `proxy` block,
a `DoctorCommand` feature probe, and a `BinaryInstaller` post-install
notice. Adopters must rebuild the upstream binary with
`cargo install gaze-cli --features proxy` to use the proxy at runtime —
the v0.8.0 GitHub-release binary asset is built without the `proxy`
feature.

### Added

- `config/gaze.php` `proxy` block (`bind`, `session_ttl`, `rulepack`,
  `policy_path`, `upstream.{openai,anthropic,gemini}`, `stop_timeout`)
  with matching `GAZE_PROXY_*` env overrides. Each key forwards as an
  exact upstream CLI flag.
- Six new Artisan commands under `Naoray\GazeLaravel\Console\Proxy\`:
  `gaze:proxy:serve`, `:start`, `:stop`, `:status`, `:logs`, `:restart`.
  `:status` translates `gaze-proxy not running` to a non-zero exit so
  CI / probes can use it directly.
- `DoctorCommand` probe for the upstream `proxy` feature availability —
  emits a `cargo install gaze-cli --features proxy` hint when the
  configured binary lacks the feature and the adopter has any non-default
  `gaze.proxy.*` value. Skipped silently when proxy is unconfigured.
- `BinaryInstaller` post-install notice that mentions the
  `--features proxy` build path. Emitted once per fresh install.
- `docs/proxy.md` adopter quickstart, config reference, daemon-lifecycle
  table, security notes, and doctor-probe section.

### Changed

- `docs/upstream-coverage.md`: new "Proxy (v0.8.1)" section with the full
  subcommand + flag mapping table. The `gaze proxy *` row was removed
  from "Deferred"; only the launchd / systemd-user stubs remain in the
  deferred table.
- `README.md`: features list + documentation index gain a proxy entry.

### Notes on deferred upstream surfaces

- `gaze proxy install-launchd` / `install-systemd-user` — upstream stubs
  these in v0.8.0 (return `"reserved for v0.8.x"`). Adapter does not yet
  wrap them; `php artisan gaze:proxy:install` will land once upstream
  implements the launchd / systemd integration.

## [0.8.0] - 2026-05-14

Upstream `EmpireTwo/gaze` v0.8.0 adapter release. Pins the v0.8.0 binary,
surfaces the bundle unification + `safety_tier` reshape, and refreshes the
published policy + doctor command for the deprecated `core-extended` alias.

### Added

- `docs/upgrading.md` — per-minor adapter upgrade guide cross-linked from
  the README, complementing upstream `UPGRADE.md`.
- `DoctorCommand` deprecation warning when `gaze.rulepacks` or the
  resolved `gaze.policy_path` lists `core-extended`. Removal target:
  v0.10.0. Skips silently on TOML parse failure (doctor is not a TOML
  linter).
- `docs/upstream-coverage.md` "Coverage by locale (v0.8.0)" table for
  the 10 new Tier 2 / Tier 3 entities across `locale-{br,fr,nl,in,uk}`
  plus the existing US / UK packs (Aadhaar, NIR, Steuer-ID, BSN, CPF,
  CNPJ, NHS, US SSN, UK NINO, Indian PAN).
- `docs/upstream-coverage.md` "Audit row columns (v0.8.0)" section
  noting that `recognizer_id` + `recognizer_version_id` flow through
  `Audit\QueryBuilder::parseRows()` verbatim — adopters index by string
  key, no typed DTO yet.

### Changed

- `BinaryInstaller::PINNED_VERSION` `0.7.2` → `0.8.0`. Help-snapshot
  fixtures re-baselined against the v0.8.0 GitHub-release binary. The
  `GAZE_VERSION` env override remains the supported pin escape hatch.
- `resources/policy.toml` ships `bundled = ["core"]` only — the v0.8
  bundle unification collapsed `core-extended` into `core`. Adopters who
  copied the published policy with `["core", "core-extended"]` see a
  runtime deprecation warning on first `php artisan gaze:doctor` run;
  switch to `["core"]` plus an explicit `--locale` (or `GAZE_LOCALE`) to
  keep `phone.national.*` / `postal.*` coverage.
- `tests/Feature/PublishedPolicySchemaTest.php` no longer asserts
  `core-extended` is present; a new regression assertion guarantees the
  unified bundle stays unified.
- `tests/Contract/VariantContractTest.php` fixture header bumped to
  v0.8.0 — variant table itself is unchanged (no new `CliError` wire
  shapes in v0.8.0).

### Notes on deferred upstream surfaces (v0.8.x family)

- `gaze proxy start | stop | status | logs | restart | install-launchd |
  install-systemd-user` daemon-mode subcommands. Adapter wrap deferred
  to v0.8.x: needs `config/gaze.php` proxy block + 5+ artisan commands.
- `Ipv4Parse` / `Ipv6Parse` / `EthEip55` validator kinds and
  `eth.address` in the published `resources/policy.toml`. Still deferred
  from v0.7.x.
- `gaze mcp install / doctor / serve` MCP runtime subcommands. Still
  deferred from v0.7.x.
- `gaze document clean` document-mode runtime. Still deferred from
  v0.7.x.

## [0.7.0] - 2026-05-13

Upstream `EmpireTwo/gaze` v0.7.x adapter release. Adopts upstream v0.7.2 as the
pinned binary, which itself was the dogfooding-driven point release closing
out PulseFlow demo findings F5 (gaze#191 — `PolicyConfig.detail` threading)
and F6 (gaze#192 — `PolicySchemaUnsupported` envelope + policy
`schema_version` field).

> **BREAKING for adopters who do not override `GAZE_VERSION`.** This release
> bumps the pinned upstream binary across the 0.6 → 0.7 boundary in a single
> step. Existing `policy.toml` files keep loading via upstream's soft-default
> `schema_version`, but adopters that pin the SemVer minor of this package
> should review the [README upgrade section](./README.md#upgrading-from-06x)
> before deploying.

### Added

- `Variant::PolicySchemaUnsupported` enum case and
  `GazePolicySchemaUnsupportedException` typed exception with `found()` /
  `supported()` accessors. Maps the upstream wire shape
  `{"error":"PolicySchemaUnsupported","exit":2,"found":"...","supported":"..."}`
  emitted when a policy's `schema_version` major.minor prefix does not match
  the binary's supported range. Shares the `exit=2` bucket with other
  config-error variants.
- `GazePolicyConfigDetailException::detail(): ?string` accessor exposing the
  upstream `detail` sidecar (e.g. `"unknown bundled rulepack: garbage"`)
  threaded through every `gaze-cli` `PolicyConfig` loader cause as of
  upstream PR191. Wire shape stays additive — existing catch blocks need no
  change.

### Changed

- `BinaryInstaller::PINNED_VERSION` `0.6.6` → `0.7.2`. Help-snapshot fixtures
  re-baselined against the v0.7.2 binary; help-text drift includes the new
  `audit safety-net` subcommand and the `--has-ambiguity` /
  `--ambiguity-reason` / `--collision-family` / `--collision-variant` audit
  filters from upstream's collision-family side-channel work.
- `tests/Contract/VariantContractTest.php` fixture now pins the
  `PolicySchemaUnsupported` row alongside the existing 17 variants.
- `docs/upstream-coverage.md` exception table extended with the new variant
  and the `detail()` accessor; banner bumped to v0.7.2.

### Notes on deferred upstream surfaces (v0.7.x family)

The following upstream additions ship in v0.7.0 / v0.7.1 but are intentionally
NOT yet exposed through this adapter release. Each is tracked separately:

- `gaze mcp install / doctor / serve` MCP subcommands (opt-in `mcp` feature).
- `gaze document clean <input> --out <dir>` document-mode CLI (opt-in
  `document` feature; PDF/PNG/JPG → SafeBundle via Tesseract + pdfium).
- New validator kinds `Ipv4Parse`, `Ipv6Parse`, `EthEip55` (and `eth.address`
  in `core-extended`) not yet surfaced in the published `policy.toml` stub.

## [0.6.6] - earlier

> Historical note: the entries below were originally tracked under
> `[Unreleased]` against the 0.6.6 binary and are folded into this release
> for the SemVer transition. They predate the v0.7.x adapter changes above.

### Added

- `Naoray\GazeLaravel\Entry` readonly DTO and `GazeSession::$entries` (`list<Entry>`) — per-rule detection metadata (`class`, `raw`, `token`, `family`) populated from the upstream `gaze clean` JSON `entries` field. Defaults to `[]` when the field is absent so callers can always iterate; dogfooding F1 (replaces hand-parsing the encrypted session blob's binary header).
- `composer.json` homepage, support, and authors blocks for Packagist discoverability.
- Six OpenAI privacy-filter config keys so Laravel apps can tune `--openai-filter-command`, `--openai-filter-checkpoint`, `--openai-filter-operating-point`, `--safety-net-timeout-ms`, `--safety-net-input-limit-bytes`, and `--safety-net-mode` without constructing `Gaze` manually.
- Upstream `gaze` v0.6.6 parity: `--session-scope` (`GAZE_SESSION_SCOPE`) on `Gaze::clean()`, `--restore-mode` (`GAZE_RESTORE_MODE`) on `Gaze::restore()`, and typed exceptions for `SafetyNetConfig`, `SafetyNet`, and `UnsupportedSessionScope`.
- `AGENTS.md` mission section plus living-roadmap convention cite for the upstream parity workflow.
- `docs/upstream-coverage.md` as the living coverage matrix for upstream CLI flags, commands, and stderr variants.
- Pint format-check CI job that runs `composer format -- --test`.

### Changed

- Pre-existing Pint format drift in `BinaryInstaller` and `Gaze` was cleaned up to enable the new format-check gate.
- Repository org renamed to `EmpireTwo`. Code/test/doc URLs sweep to canonical name; GitHub redirects keep historical refs resolvable. Two CHANGELOG history entries preserve their original org/repo wording to keep historical accuracy intact.
- Pinned upstream `gaze` Rust binary URL switches to `EmpireTwo/gaze` (`BinaryInstaller::RELEASE_BASE` + `GazeServiceProvider` NER manifest fetch).
- `BinaryInstaller::PINNED_VERSION` now points at upstream `gaze` v0.6.6 and Linux binary asset detection matches the published `gaze-x86_64-linux-gnu` release asset.

### Fixed

- `config/gaze.php` safety-net description now references the correct `--safety-net=openai-filter` flag arity.
- `gaze:install-ner` now persists the verified `SHA256SUMS` manifest at the NER `model_dir` so upstream `gaze` v0.7.x can locate the sidecar required for runtime detection. `--check` reports failure when the file is missing or drifted; re-installs self-heal the sidecar idempotently.
- `gaze:install-ner --update-policy` now writes an absolute `[ner].model_dir` to `policy.toml`. Previously a relative dest (or a `--dest=storage/app/...` override) leaked a relative path into the policy file, which upstream `gaze` CLI resolves against the current working directory rather than the policy file's location — silently failing when Laravel launches `gaze` from a non-project CWD (PulseFlow dogfooding 2026-05-13 F#4). `PolicyTomlPatcher` now resolves relative `model_dir` against the project base path (or `getcwd()` fallback) before patching.

### Tests

- Renamed two F4 installer tests in `tests/Unit/Install/NerInstallerTest.php` to reflect that they verify absolute-dest passthrough end-to-end (the original names claimed relative→absolute coverage but both passed absolute paths, so `PolicyTomlPatcher::absolutize()` short-circuited). Added a new test that drives a truly relative `dest` through the installer (`chdir($baseDir)` + null patcher baseDir) and asserts the written `policy.toml` carries the absolute resolution (nit #322, PR #73 follow-up).

## [0.6.4] - 2026-05-08

OSS readiness wave: lockstep with upstream `EmpireTwo/gaze` v0.6.4, README turned into a promo page with deep content moved to `docs/`, and a fix for the broken binary download path that shipped with the unreleased v0.6.5 metadata.

### Added

- PHP adapter coverage for the upstream v0.6.4 CLI surface, including locale, bundled rulepacks, custom rulepack paths, audit query, safety-net, and OpenAI filter device arguments.
- `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` config support so `--rulepack-path=` is reachable through the service container.
- Configuration reference, exception guide, testing guide, queue guide, getting-started docs, blob lifecycle docs, Livewire side-by-side notes, Pest architecture notes, and conversational-loop guidance.
- Recreated GitHub Actions test matrix for PHP 8.2/8.3 across Laravel 11/12.

### Changed

- README slim-down: deep content moved to dedicated `docs/*.md` files. README is now a promo page (~100 lines, was ~340).
- Retarget the upcoming OSS cut to upstream `EmpireTwo/gaze` v0.6.4, the latest published release, instead of the unreleased v0.6.5 metadata used during audit prep.
- `php artisan gaze:install-ner --force` is now the single Laravel-idiomatic gate for both non-interactive confirmation and overwriting existing destination/policy state.
- NER artifact integrity now resolves `SHA256SUMS` from the upstream release URL instead of carrying a stale static checksum file.
- The pre-push hook gained a docs-only fast path before being removed for OSS packaging; historical v0.5.0 notes still document the old contributor-local hook behavior.
- OSS docs and metadata were scrubbed for public packaging, including license, gitignore, binary install guidance, security model, and quickstart entry points.

### Fixed

- `Gaze::clean()` now passes `--safety-net=openai-filter`; PR #48 originally emitted bare `--safety-net`, which v0.6.x binaries reject.
- `BinaryInstaller::PINNED_VERSION` now points at `0.6.4`, avoiding 404s for the nonexistent upstream v0.6.5 release.
- Intel Mac install guidance is reachable again after fixing the `detectTarget()` branch ordering.
- Help/version snapshots and contract docblocks now reference the pinned v0.6.4 upstream contract.
- Dead code and audit-found drift from the OSS readiness pass were cleaned up.

### Removed

- Repository-managed `.githooks/pre-push` and composer `post-install-cmd` / `post-update-cmd` auto-wiring that changed contributors' `core.hooksPath` during install/update.
- Stale packaged NER SHA fixture that duplicated upstream release checksums.

## [0.6.0] - 2026-04-29

NER opt-in wave: ships `gaze:install-ner` for one-command Davlan mBERT NER int8 ONNX setup, lockstep with upstream gaze v0.5.2 (canonical NER asset contracts), and bundles the polish PR follow-ups from PR #40 review nits + GH issues #1/#2/#8/#9.

### Added

- `php artisan gaze:install-ner` — opt-in command to download and verify the pinned Davlan mBERT NER int8 ONNX artifact set and optionally wire `[ner]` into `policy.toml`. Includes packaged upstream NER labels/policy contracts, SHA256SUMS validation, idempotent installs, dry-run/check modes, policy backups, and fail-closed mismatch handling. Closes #32.
- `RequiresFreshClean` marker contract for blob-expired / invalid-blob-version exceptions so queue consumers can branch on the recovery path without duplicating subclass lists.

### Changed

- Bump pinned upstream `EmpireTwo/gaze` from v0.5.0 to v0.5.2 (docs/scripts/assets refresh; CLI surface unchanged).
- `GazeServiceProvider` now defers container bindings until first use, and `GazeException` subclasses own their log-level classification.
- PHPStan now analyzes `tests/` as well as `src/`; shared test helpers live in `tests/Helpers.php` and the direct PHPUnit dev constraint is removed in favor of Pest's transitive PHPUnit dependency.
- `GazeRetryPolicy` now supports Laravel-style `array<int>` backoff schedules and reports the missing queue method (`fail` / `release`) when a consumer job lacks the expected queue traits.

### Fixed

- Help snapshot version text now matches the pinned upstream `gaze 0.5.2` binary.
- `NerInstaller` now fails closed when writing destination `.gitignore` fails and only removes a destination directory during rollback after the new staged directory was actually placed.
- `Gaze::restore()` now forwards `--max-bytes` and pre-flights the wrapped `{session_blob, text}` JSON payload, not only the caller-supplied text.
- Cross-session isolation integration coverage now asserts ciphertext divergence before documenting the current upstream legacy behavior.

### Documentation

- `GazeException` documents that inherited `getCode()` values are upstream process exit codes, not HTTP or app-domain status codes.

## [0.5.0] - 2026-04-29

Adopter ergonomics wave: ships a real multi-class default policy, a cold-latency diagnostic command, contributor-side test enforcement, and consolidates several v0.4.5+v0.5.0 lockstep changes that accumulated post-v0.4.0.

### Security

- Production deployments now ignore `GAZE_RELEASE_BASE` env override and always fetch from the canonical release host. The override remains available for non-production (testing/staging) flows. Closes #194.

### Added

- `php artisan gaze:bench --requests=N [--json]` for adopter latency baselines under the current cold, one-shot `gaze clean` contract. Output includes `bench_schema_version`, `mode`, `first_ms`, chronological `samples_ms`, percentiles, and an env fingerprint. Refs #33.
- Help snapshot contract covering the pinned `gaze` CLI surface (`--version`, top-level help, `clean`, `restore`, `audit`, and `audit purge`) so upstream command/help drift is visible in adapter tests.
- Audit purge foundation: `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH`, clean-side `--audit-db` forwarding, `Gaze::audit()->purge()->before(...)->dryRun()` / `execute()`, fake audit assertions, and audit docs.
- Pre-push git hook (`.githooks/pre-push`) that runs `composer test` + `composer analyse` before every push. `composer install` / `composer update` auto-wires `core.hooksPath` to `.githooks` so contributors get local enforcement without manual setup. Bypass with `git push --no-verify`.

### Changed

- Bump pinned upstream `EmpireTwo/gaze` from v0.4.5 to v0.5.0. v0.5.0 is a workspace-shape refactor (extracted `gaze-types` + `gaze-audit` crates, Dylint-based audit-isolation gate); CLI binary contract is unchanged so the adapter does not need behaviour changes. Help snapshots regenerated against the v0.5.0 binary.
- Adapter Encrypter cipher now follows host `config('app.cipher')` instead of hardcoded AES-256-CBC (#18, closes #209). Aligns with Laravel 11+ AES-256-GCM default. Existing deployments unaffected unless they relied on the hardcode mismatching the host. Cross-config regression test added.
- `policy.toml.example` rewritten as a multi-class v0.4 default. Activates `core` + `core-extended` rulepacks, ships 4 custom recognizers (money amount, invoice/order number, street address, org with legal suffix), uses BCP47 locales (`de-DE` + `en-US`) so postal recognizers actually fire, and keeps `[ner]` commented (opt-in via #32's `php artisan gaze:install-ner`). Migrates from the retired `[[detector]]` v0.3 surface to v0.4 `[[policy.custom_recognizers]]`. Money-amount regex is bounded to prevent partial matches inside identifiers and fixes the `\d{1,3}` thousand-separator bug from #31. Schema-shape test plus real-binary integration round-trip locks the contract. Closes #31.
- `.github/workflows/test.yml` auto-triggers (`push: main`, `pull_request`) temporarily disabled while GitHub Actions billing on the PIInuts org is being resolved. Workflow stays present and is invokable via `workflow_dispatch` (manual run from the Actions tab). Local enforcement via the new pre-push hook covers the same `composer test` + `composer analyse` checks. Re-enable by uncommenting the two trigger blocks in the workflow file.

### Fixed

- GazeSigPipeException now classified as Retryable+alert instead of dead-lettering on first occurrence. Closes #210.
- `GazeInstallerPlugin::uninstall()` now removes `vendor/bin/gaze` on `composer remove` instead of leaving it orphaned. Closes #213.
- `GazeInstallerPlugin::uninstall()` now correctly handles relative `bin-dir` Composer configs by normalizing against `vendor-dir`.
- Composer plugin install no longer uses `shell_exec` (#19, closes #197 + #217). `BinaryResolver` resolves the binary via Symfony `ExecutableFinder`; `BinaryInstaller::alreadyInstalled` invokes the binary via Symfony `Process`. The plugin now runs in container, Alpine, and `disable_functions=shell_exec` environments where the previous code path silently failed at install time.

### Documentation

- `MIGRATION-v0.3.md` gap-fill (#20, closes #206). Expanded four under-documented v0.3 breaking changes: (1) `fail_closed` config + `GAZE_FAIL_CLOSED` env removal (fail-closed is now permanent), (2) `Context` / `ContextResolver` removal in favor of policy-file-driven detection, (3) `RestoredText` DTO removal — `restore()` now returns `string`, (4) full rename table for the exception hierarchy (`GazeSanitizeFailedException` / `GazeRestoreFailedException` + `Terminal*` / `Transient*` markers → variant-typed subclasses + `NonRetryable` / `Retryable` / `RetryableWithAlert`).

## [0.4.0] - 2026-04-26

> **Historical note (post-2026-05 public flip):** This release predates both the public flip of `EmpireTwo/gaze` and the vendor rename to `empiretwo/gaze-laravel`. The install instructions below required a GitHub Personal Access Token because `gaze` was a private repo, and reference the legacy package name `naoray/gaze-laravel`. **No PAT is required as of v0.6.x, and the current package name is `certamesh/gaze-laravel` (briefly `empiretwo/gaze-laravel` in between).** Adopters on current versions should follow `README.md` install instructions instead. The block below is preserved for reproducibility of historical builds.

First stable adopter-facing release. Bundles the v0.3 retarget with full upstream `gaze` v0.4.5 lockstep, real Composer-plugin-driven binary install, `GAZE_GITHUB_TOKEN` auth for private upstream artifacts, and CI matrix coverage.

### Added

- **Composer plugin install hook (#12).** `GazeInstallerPlugin` (`PluginInterface` + `EventSubscriberInterface`) auto-downloads the `gaze` binary into `vendor/bin/` on `composer require naoray/gaze-laravel` (legacy package name). Subscribes to `POST_PACKAGE_INSTALL` and `POST_PACKAGE_UPDATE` for the package itself. Previously `BinaryInstaller::postInstall` was orphaned dead code (no `extra.class`, no consumer-side script wiring) so adopters never auto-got the binary.
- **`GAZE_GITHUB_TOKEN` env (#16).** Authenticated downloads from the private `EmpireTwo/gaze` release artifacts via GitHub asset-id resolution + `Authorization: Bearer` + `Accept: application/octet-stream`. Required for adopter installs while the upstream repo is private. `Authorization` header is stripped on cross-host redirects (api.github.com → S3 signed URL) so the token never leaves GitHub. README documents the token + required scopes.
- **CI matrix workflow (#13).** `.github/workflows/test.yml` runs Pest + PHPStan on PHP 8.2/8.3 × Laravel 11/12, ubuntu-latest. CI sets `GAZE_SKIP_BINARY_DOWNLOAD=1` (wrapper-only test scope; binary integration test is a follow-up). Concurrency cancels in-progress runs on the same ref. Action versions pinned.
- **Variant lockstep with upstream `gaze` v0.4.5 (#14).** `Variant::PolicyConfigDetail` + `Variant::AuditPurgeIso8601` enum cases, matching `GazePolicyConfigDetailException` and `GazeAuditPurgeIso8601Exception` typed exceptions, `Gaze::buildException` mapFailure arms, and `Variant::exitBucket()` arms. `PolicyConfigDetail` shares its wire name with `PolicyConfig` upstream and is disambiguated via the `detail` sidecar in `Variant::tryFromStderr`.
- **Variant contract test (#14).** `tests/Contract/VariantContractTest.php` is a fixture-based parity guard asserting every PHP `Variant` matches upstream `crates/gaze-cli/src/error.rs:8-23` for `(name, exit, wire shape)`. Catches drift in either direction.
- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - `policy_path`, `max_bytes`, and `session_ttl_seconds` config surfaces.
  - `policy.toml.example` publish target.
  - `GazeRetryPolicy`, retry marker contracts (`TerminalGazeException` / `TransientGazeException`), and `GazeInfraAlert`.
  - `Gaze::fake()` facade test double with Laravel-idiomatic assertion helpers.
  - `MIGRATION-v0.3.md`.

### Changed

- **`PINNED_VERSION` `0.3.0` → `0.4.5` (#14).** Adapter is now lockstep with the current upstream stable release.
- **`RELEASE_BASE` repointed `Naoray/gaze` → `piinuts/gaze` (#12)** post org transfer 2026-04-26. GitHub redirects today; the new canonical URL avoids future redirect-TTL rot.
- **`config('gaze.binary')` default `'gaze'` → `null` (#15).** Without this fix, `BinaryResolver` short-circuits on the literal string `'gaze'` and never discovers the auto-installed binary at `vendor/bin/gaze`. PR #12 was effectively a no-op for default installs without #15. `null` = auto-discover `vendor/bin/` then `PATH`; non-empty string = explicit override. Empty string still coerces to `null` in `GazeServiceProvider::register`.
- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - **BREAKING.** Retargeted the adapter from `ghostwriter` to the `gaze` v0.3 CLI contract.
  - **BREAKING.** Replaced `sanitize()` with `clean()` and removed the old `Context` envelope.
  - **BREAKING.** `restore()` now consumes `GazeSession` and returns the restored string directly.
  - Replaced substring-based stderr handling with a typed variant parser and dedicated exception hierarchy.
  - Session blobs are now stored as encrypted `EncryptedBlob` values instead of plaintext strings.

### Removed

- **Inherited from the v0.3 retarget (never shipped as a tagged release):**
  - `Context`
  - `ContextResolver`
  - `RestoredText`
  - `GazeSanitizeFailedException`
  - `GazeRestoreFailedException`
  - legacy fail-open behavior

### Adopter-visible behavior change (upstream `gaze` v0.4.5)

- **`core-extended` bundle now activates `postal.us` + `postal.de` recognizers under no-policy invocation.** Bare 5-digit numerics will tokenize. To restore prior behavior, pass `--locale=global` to `gaze` invocations or supply a policy with narrower locale gating.

### Migration notes

- **From the unreleased `[Unreleased]` state (v0.3 retarget):** v0.4.0 is purely additive on top. No further breaking changes beyond what `[Unreleased]` already had.
- **From a pre-v0.3 fork (ghostwriter-era):** read [`MIGRATION-v0.3.md`](./MIGRATION-v0.3.md) first. v0.4.0 inherits all v0.3 breaking changes (sanitize → clean, Context removal, GazeSession restore signature).

### Install

```bash
composer require naoray/gaze-laravel:^0.4.0 # legacy package name
```

The upstream `EmpireTwo/gaze` repo is currently private, so binary download requires a GitHub PAT with `repo` scope:

```env
GAZE_GITHUB_TOKEN=[redacted historical PAT placeholder]
```

Set this in `.env` **before** running `composer require`. Without it, the binary download will 404.

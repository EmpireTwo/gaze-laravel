# Gaze-Laravel Architecture

> Audit snapshot: v0.6.4 · Generated 2026-05-07

---

## 1. System Overview

```
GazeServiceProvider (DeferrableProvider)
  │
  ├─ BinaryResolver          – locates the gaze binary
  ├─ Gaze                    – main entry point (clean / restore / audit)
  ├─ AuditService            – audit purge + query verbs
  ├─ NerInstaller            – NER model install orchestration
  ├─ LaravelNerFetcher       – HTTP download + SHA256 verification
  └─ gaze.encrypter          – dedicated Encrypter (falls back to APP_KEY)

Request / Job
  │
  ▼
Gaze::clean(text)
  ├─ assertInput()           – UTF-8 + size pre-flight (PHP side)
  ├─ BinaryResolver::resolve()
  ├─ ProcessFactory::run()   – gaze clean --policy=… --format=json [v0.6.4 flags]
  └─ GazeSession             – { cleanText, ciphertext:EncryptedBlob, detections, entries:list<Entry> }

Gaze::restore(session, text)
  ├─ EncryptedBlob::decryptedBlob()
  ├─ ProcessFactory::run()   – gaze restore --format=json
  └─ string (restored text)

Gaze::audit(?path)
  └─ AuditService
       ├─ purge() → PurgeBuilder → dryRun() / execute() → AuditPurgeResult
       └─ query() → QueryBuilder → execute()              → list<list<string>>

Queue/
  ├─ GazeRetryPolicy::classify(e) → RetryAction
  └─ GazeRetryPolicy::dispatch(e, job)

Install/ (Composer time, not runtime)
  ├─ GazeInstallerPlugin     – Composer plugin entry-point
  ├─ BinaryInstaller         – downloads gaze binary, verifies SHA256
  └─ NerInstaller            – fetches NER model files, verifies SHA256
       └─ LaravelNerFetcher  – HTTP (Symfony HttpClient) + copy from package
```

### Key design constraints

- The `gaze` binary is a Rust subprocess; PHP communicates via stdin (JSON for
  restore) and stdout (JSON). Stderr carries structured `{error, exit}` JSON for
  error classification.
- All public API is in `Gaze`, `AuditService`, `PurgeBuilder`, `QueryBuilder`,
  and the `Facades/Gaze` static helpers.
- `runForAuditPurge` and `runForAuditQuery` are `public @internal` — they exist
  only so `PurgeBuilder` and `QueryBuilder` (in the same package but different
  namespace) can call into the process runner without exposing a generic shell
  exec surface. PHP has no package-private visibility.

---

## 2. Session Blob Lifecycle

```
Gaze::clean(text)
  │
  ├─ subprocess: gaze clean → { clean_text, session_blob, stats, entries? }
  │                                         │
  │                              base64-encoded binary blob
  │                              (gaze-internal tokenization map)
  │                                         │
  │                          EncryptedBlob::wrap(session_blob)
  │                            └─ Encrypter::encryptString()
  │                                 uses gaze.encrypter (GAZE_ENCRYPTION_KEY
  │                                 or APP_KEY fallback, AES-256-CBC, fresh IV)
  │                                         │
  └─ GazeSession { cleanText, ciphertext:EncryptedBlob, detections, entries:list<Entry> }
       ↑ entries[] surfaces per-rule detection metadata (class, raw, token, family)
         when the upstream CLI emits an `entries` JSON field. Defaults to [] for
         current upstream releases where the field is absent.
       │
       │  (stored in queue payload, cache, or passed to restore call)
       │
Gaze::restore(session, text)
  │
  ├─ EncryptedBlob::decryptedBlob()
  │    └─ Encrypter::decryptString()  ← DecryptException → GazeResponseDecodeException
  │                                         │
  │                              raw session blob (plaintext)
  │                                         │
  ├─ JSON-encode { session_blob, text } → subprocess stdin
  └─ subprocess: gaze restore → { text } → restored PII text
```

**Security properties:**
- The session blob is encrypted at rest in `GazeSession::$ciphertext`.
- The encrypter generates a fresh IV per `encryptString()` call — no IV reuse.
- Key falls back to `APP_KEY` when `GAZE_ENCRYPTION_KEY` is unset; boot fails
  loudly if `GAZE_ENCRYPTION_KEY` is set but malformed.
- No PII or raw session blob is logged. `toLogContext()` surfaces only
  `exit_code`, `error_variant`, and `stderr_sha256` (SHA256 of binary stderr,
  never plaintext).

---

## 3. Audit Log Flow

```
Gaze::clean(text, --audit-db=path)
  └─ gaze binary writes redaction event to SQLite DB at `path`

Gaze::audit(?path)->purge()->before($ts)->execute()
  └─ AuditService::purge()
       └─ PurgeBuilder::runPurge(dryRun:false)
            └─ Gaze::runForAuditPurge([gaze, audit, purge, --audit-db=…, --before=…])
                 └─ AuditPurgeResult { rawOutput, count }

Gaze::audit(?path)->query()->execute()
  └─ AuditService::query()
       └─ QueryBuilder::execute()
            └─ Gaze::runForAuditQuery([gaze, audit, query, --audit-db=…])
                 └─ parseRows() → list<list<string>>  (TSV: tab-delimited rows)
```

The audit DB path is resolved in priority order:
1. Per-call override: `Gaze::audit($path)`
2. Config: `gaze.audit_db_path` / `GAZE_AUDIT_DB_PATH`
3. If neither is set → `GazeAuditDbNotConfiguredException` at call time (never
   at boot). `Gaze::clean()` continues to work without audit.

---

## 4. Exception Taxonomy

All exceptions extend `GazeException extends \RuntimeException` — including the `CertaMesh\Gaze\Install\Ner*Exception` family thrown by `gaze:install:ner` and the Composer plugin (via their `NerInstallException` base).

| Category | Base class | Retry contract | Exit bucket | Members |
|---|---|---|---|---|
| **Caller bug** | `GazeCallerBugException` | `NonRetryable` | 1 | `GazeEmptyInputException`, `GazeInputTooLargeException`, `GazeInvalidEncodingException`, `GazeStdinParseException`, `GazeAuditDbNotConfiguredException`, `GazeAuditPurgeIso8601Exception` |
| **Ops / config** | `GazeOpsConfigException` | `NonRetryable` | 2 | `GazePolicyConfigException`, `GazePolicyConfigDetailException`, `GazeBinaryMissingException` |
| **Integrity** | `GazeIntegrityException` | Mixed | 3 | `GazeUnknownTokenException` (NonRetryable), `GazeInvalidSignatureException` (NonRetryable), `GazeInvalidBlobVersionException` (NonRetryable + RequiresFreshClean), `GazeBlobExpiredException` (NonRetryable + RequiresFreshClean), `GazeResponseDecodeException` (NonRetryable), `GazePipelineException` (Retryable) |
| **Infra** | `GazeInfraException` (abstract) | Mixed | 4 / 141 | `GazeIoException` (RetryableWithAlert), `GazePolicyOpenException` (NonRetryable), `GazeSigPipeException` (RetryableWithAlert), `GazeTimeoutException` (RetryableWithAlert) |

**Exit-bucket → category mapping** (when binary stderr is not JSON):

| Exit | `Variant::unknownFor()` fallback |
|---|---|
| 1 | `StdinParse` |
| 2 | `PolicyConfig` |
| 3 | `UnknownToken` |
| 4 | `Io` |
| 141 | `SigPipe` |

**`RequiresFreshClean`** marker interface: set on `GazeBlobExpiredException` and
`GazeInvalidBlobVersionException`. Consuming jobs should check
`$e instanceof RequiresFreshClean` and re-run `Gaze::clean()` before retrying
`Gaze::restore()`.

**Queue retry dispatch** (`GazeRetryPolicy`):

| `RetryAction` | Trigger | Effect |
|---|---|---|
| `Fail` | `NonRetryable` | `$job->fail($e)` — permanent failure |
| `ReleaseWithBackoff` | `Retryable` | `$job->release($delay)` — silent retry |
| `ReleaseWithAlert` | `RetryableWithAlert` | fires `GazeInfraAlert` event, then `$job->release($delay)` |
| `Throw` | unknown exception | re-throws — lets framework handle it |

Exceptions whose retry lane depends on runtime state implement
`HasRetryDisposition` instead of a static marker; `classify()` consults it
before the marker arms and returns `$e->retryDisposition()` directly.
`GazeSafetyNetFailureException` (variant-dependent: `Timeout` retries,
`SuspectedLeak` alerts, `InputTooLarge`/unknown variants fail) is the sole
implementer today.

---

## 5. Known Limitations and Design Constraints

### L-1 — `public @internal` process runners

`Gaze::runForAuditPurge()` and `Gaze::runForAuditQuery()` are `public` to allow
`PurgeBuilder` and `QueryBuilder` (different namespaces, same package) to invoke
them. PHP has no package-private visibility. Callers outside the package should
treat these as unstable internal API subject to change without a major version.

### L-2 — `stderrHash` is SHA256 of empty string for synthetic PHP-side errors

When the error originates in PHP (timeout via `ProcessTimedOutException`, JSON
decode failure, `DecryptException`), no subprocess stderr is available. The
`stderrHash` field in the exception and log context is always
`e3b0c44...` (SHA256 of `""`). This is a known limitation; the `exit_code: -1`
in the same context distinguishes these synthetic records from real subprocess
failures.

### L-3 — `EncryptedBlob` couples to Laravel's service container

`EncryptedBlob::encrypter()` calls `app('gaze.encrypter')` or `app('encrypter')`
statically. This is idiomatic for Laravel packages but makes the class hard to
unit-test in isolation. The encrypter binding is always registered before
`EncryptedBlob` is used at runtime.

### L-4 — Retry storm risk on repeated timeouts

`GazeTimeoutException` implements `RetryableWithAlert`. If the timeout is caused
by consistently oversized input (edge case: input just under the PHP-side
`max_bytes` ceiling but too large for the binary's actual processing budget),
retries will time out indefinitely. Consumer jobs should set a `$maxTries` or
`$maxExceptions` limit.

### L-5 — Intel Mac (x86_64) binary not available

Pre-built binaries are ARM-only. macOS x86_64 users must install from source:

```bash
git clone https://github.com/CertaMesh/gaze && cd gaze && cargo install --path crates/gaze-cli
```

<!-- TODO(gaze-v0.7): mention `cargo install gaze-cli` once published to crates.io. -->

Then set `GAZE_BINARY=/path/to/gaze` in `.env`.

---

## 6. Audit Findings

Severity: **HIGH** / **MEDIUM** / **LOW**

### HIGH

| # | File | Line | Finding | Status |
|---|---|---|---|---|
| H-1 | `src/GazeServiceProvider.php` | 60 | `rulepackPaths` hardcoded to `null`; `gaze.rulepack_paths` / `GAZE_RULEPACK_PATHS` config key absent — the `--rulepack-path=` CLI flag is unreachable via the service container | **Fixed in this PR** |
| H-2 | `src/Install/BinaryInstaller.php` | 60 | Dead code: `$target === 'x86_64-apple-darwin'` branch is unreachable — `detectTarget()` returns `null` for macOS x86_64, so the `null`-check fires first; the Intel-Mac `cargo install` guidance is silently lost | **Fixed in this PR** |

### MEDIUM

| # | File | Line | Finding |
|---|---|---|---|
| M-1 | `tests/Unit/ArgvAssemblyTest.php` | — | No positive test for `rulepackPaths` → `--rulepack-path=` flag; omission test exists but positive coverage is missing |
| M-2 | `src/Audit/QueryBuilder.php` | — | `QueryBuilder` and its TSV `parseRows()` have zero test coverage |
| M-3 | `src/Exceptions/` | — | Seven leaf exception classes not `final`: `GazeBlobExpiredException`, `GazeSigPipeException`, `GazeIoException`, `GazeTimeoutException`, `GazeUnknownTokenException`, `GazePolicyOpenException`, `GazeBinaryMissingException` — subclassing changes retry contract silently |
| M-4 | `src/Facades/Gaze.php` | — | `assertNothingRestored()` absent — asymmetric vs. `assertNothingCleaned()` / `assertNothingAudited()` |

### LOW

| # | File | Line | Finding |
|---|---|---|---|
| L-1 | `src/EncryptedBlob.php` | 30 | Static `app()` call — untestable outside Laravel boot |
| L-2 | `src/Gaze.php` | 194, 207 | `stderrHash = hash('sha256', '')` in synthetic error contexts — see L-2 constraint above |
| L-3 | `src/Exceptions/GazeIntegrityException.php` | — | `requiresFreshClean()` method on base class + `RequiresFreshClean` marker interface on subclasses — two paths for the same semantic |
| L-4 | `src/Exceptions/GazeBinaryMissingException.php` | 9 | `stderrHash = ''` default — deviates from convention (should be `hash('sha256', '')`) |
| L-5 | `src/Install/BinaryInstaller.php` | `alreadyInstalled()` | Version check uses `str_contains($output, $version)` — fragile if binary output format changes |
| L-6 | `tests/Contract/VariantContractTest.php` | 15 | Docblock says "gaze v0.5.2" — stale, should reference v0.6.4 |
| L-7 | `src/GazeSession.php` | — | `ciphertext` is `public readonly` — callers can invoke `decryptedBlob()` directly; consider a `getCiphertext()` accessor that returns only the opaque ciphertext string |

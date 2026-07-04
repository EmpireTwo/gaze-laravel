# Daemon

`gaze-laravel` v0.11.0 ships a Facade + config + Artisan surface for the
upstream `gaze daemon` JSONL stdio runtime — a long-lived
redaction worker that keeps one process, one policy-loaded pipeline,
and any configured model load hot across requests. Adopters call into
the daemon from a multi-turn agent loop or worker without paying the
binary startup + model cold-start cost on every turn.

Upstream reframes this runtime as a stdio server in the LSP / MCP /
language-server-protocol tradition — a foreground child process
inheriting stdin/stdout from its supervisor — rather than a Unix
daemon in the strict sense (no fork, no detach, no PID file). The
subcommand verb remains `gaze daemon` through gaze v0.9.x; the Facade,
config keys, and exception types in this package keep that name.

> **Reversibility caveat.** Daemon mode is **clean-only**. The protocol
> does NOT emit the signed `session_blob` that one-shot `Gaze::clean()`
> produces, and there is no `restore` request type. `DaemonSession` does
> not expose `restore()`. For round-trip reversal stay on the one-shot
> path: `Gaze::clean($text)` produces a `GazeSession` carrying the blob,
> hand `cleanText` to your LLM, then call `Gaze::restore($session,
> $reply)` to invert. North-star Principle 4 (reversibility) is honored
> exclusively by the one-shot contract.

## TL;DR

```bash
# 1. Rebuild upstream with the daemon feature (one-time per host, if gated)
cargo install gaze-cli --features daemon

# 2. Set a policy path and start the foreground wrapper under your supervisor
GAZE_DAEMON_POLICY_PATH=/etc/gaze/policy.toml php artisan gaze:daemon:serve

# 3. From application code
Gaze::daemon()->session('agent-thread-a')->clean($prompt);
```

> **Opt-in upstream feature.** The published GitHub-release `gaze`
> binary may be built **without** `--features daemon`. Doctor's
> pre-flight surfaces the exact `cargo install` hint when it detects
> daemon configuration against a binary missing the subverb.

## When To Use

- Multi-turn agent loops that redact every assistant turn.
- Worker queues processing dozens-to-thousands of short documents.
- Any caller that would otherwise pay binary startup + Kiji ORT init on
  every redaction.

Use the one-shot `Gaze::clean()` / `Gaze::restore()` path when:

- You need reversibility (daemon is clean-only).
- You only have one document to redact in a CLI script or batch.
- You want the signed `session_blob` for cross-process restore.

## Prerequisites

- The `gaze` binary on `PATH` (or `GAZE_BINARY` / `GAZE_DAEMON_BINARY_PATH`).
- A policy TOML file on disk (`gaze.daemon.policy_path`). Use
  `php artisan vendor:publish --tag=gaze-policy` to seed
  `policy.toml` and edit from there.
- A supervisor (systemd, Horizon process, supervisord, Forge daemon).
  The adapter does NOT daemonize; it ships a foreground wrapper.

## Config

`config/gaze.php` exposes a `daemon` block with flat keys. All keys
default to `null` so the upstream binary applies its own defaults;
populating a key forwards the matching flag.

| Config key | Env override | Default | Effect |
|---|---|---|---|
| `gaze.daemon.policy_path` | `GAZE_DAEMON_POLICY_PATH` | `null` | Forwarded as `--policy=`. Setting this key is the opt-in signal; doctor's daemon section stays silent while null. |
| `gaze.daemon.audit_db_path` | `GAZE_DAEMON_AUDIT_DB_PATH` | `null` | Forwarded as `--audit-db=`. Daemon-emitted rows stamp `provenance_stage = "daemon"`. |
| `gaze.daemon.request_timeout_ms` | `GAZE_DAEMON_REQUEST_TIMEOUT_MS` | `5000` | Adapter-side per-request ceiling. Raise it for cold first requests when policy + Kiji ORT init exceed 5s. |
| `gaze.daemon.idle_timeout_s` | `GAZE_DAEMON_IDLE_TIMEOUT_S` | `null` | Forwarded as `--idle-timeout=`. Daemon exits cleanly when no request arrives within the window. |
| `gaze.daemon.session_idle_timeout_s` | `GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S` | `null` | Forwarded as `--session-idle-timeout=`. Sessions idle beyond the window are evicted (upstream default 3600 s). |
| `gaze.daemon.session_cap` | `GAZE_DAEMON_SESSION_CAP` | `null` | Forwarded as `--session-cap=`. Maximum live sessions before LRU eviction (upstream default 1000). |
| `gaze.daemon.ner_model_dir` | `GAZE_DAEMON_NER_MODEL_DIR` | `null` | Forwarded as `--ner-model-dir=`. Overrides the policy `[ner].model_dir`. |
| `gaze.daemon.ner_locale` | `GAZE_DAEMON_NER_LOCALE` | `null` | Forwarded as `--ner-locale=`. Overrides the policy `[ner].locale`. |
| `gaze.daemon.kiji_distilbert_locales` | `GAZE_DAEMON_KIJI_DISTILBERT_LOCALES` | `null` | Forwarded as `--kiji-distilbert-locales=`. Locale list for the Kiji DistilBERT safety-net backend (no top-level one-shot equivalent). |
| `gaze.daemon.binary_path` | `GAZE_DAEMON_BINARY_PATH` | `null` | Override for the `gaze` binary path used by `:serve`. Falls back to `BinaryResolver` resolution. |
| `gaze.daemon.stderr_path` | `GAZE_DAEMON_STDERR_PATH` | `null` | File path the daemon's stderr is appended to when spawned via the adapter's `DaemonClient`. Null inherits stderr from the supervisor. |

### Shared pipeline keys

Both daemon spawn paths — `gaze:daemon:serve` and the `Gaze::daemon()`
facade (which spawns the daemon through `DaemonClient`) — assemble their
argv from one shared assembler (`Daemon\DaemonArgv`), so the two paths
carry **full flag parity by construction** and cannot drift. Both also
forward the shared pipeline flags from the **same top-level `gaze.*`
keys the one-shot `Gaze::clean()` path uses**, so a configured pipeline
behaves identically in every runtime — no duplicate daemon-scoped
copies to keep in sync:

- `gaze.locale` → `--locale=`
- `gaze.ner_threshold` → `--ner-threshold=`
- `gaze.safety_net` (truthy) → `--safety-net=openai-filter`
- `gaze.safety_net_backend` → `--safety-net-backend=`
- `gaze.safety_net_device` → `--openai-filter-device=`
- `gaze.openai_filter_command` / `_checkpoint` / `_operating_point` → `--openai-filter-*=`
- `gaze.kiji_backend`, `gaze.kiji_distilbert_command`, `gaze.kiji_distilbert_model_dir` → `--kiji-*=`
- `gaze.safety_net_timeout_ms` / `_input_limit_bytes` / `_mode` / `_fallback` → `--safety-net-*=`

Safety-net artifact paths and backend selectors are **config-only** —
mirroring the one-shot posture that artifacts are deployment config, not
per-invocation knobs. See the
[upstream coverage matrix](../reference/upstream-coverage.md#daemon-flags)
for the full flag ↔ key table.

Intentionally NOT shipped: `gaze.daemon.events.enabled`,
`gaze.daemon.extra_flags`, and connections-style
`gaze.daemon.connections.{name}.*`. See "Out of scope" below.

## Artisan Commands

TWO commands. Supervision is OS-owned (systemd / Horizon / supervisord)
— this package does not invent a Laravel-side daemon supervisor.

| Artisan | Upstream | Behaviour |
|---|---|---|
| `php artisan gaze:daemon:serve` | `gaze daemon` | Foreground wrapper. Blocks. Streams stdout/stderr verbatim. SIGTERM/SIGINT are forwarded to the child via pcntl handlers so supervisor stop signals reach the graceful-shutdown loop. Use as a systemd `ExecStart=` or a Horizon-process command. Forwards the full v0.11.1 `gaze daemon` flag surface from config; operational knobs can be overridden per-invocation: `--policy=`, `--idle-timeout=`, `--session-idle-timeout=`, `--session-cap=`, `--audit-db=`, `--locale=`, `--ner-threshold=`. |
| `php artisan gaze:daemon:status` | n/a | Best-effort `pgrep -af "gaze daemon"`. Returns visible PIDs. **NOT** a supervisor — daemons launched under a different UID, or by your supervisor in an isolated cgroup, are invisible. Query your supervisor for ground truth. |

### Launch examples

```bash
# Config-driven (recommended under a supervisor): the pipeline keys are
# the same top-level gaze.* env vars the one-shot path reads.
GAZE_DAEMON_POLICY_PATH=/etc/gaze/policy.toml \
GAZE_DAEMON_SESSION_CAP=500 \
GAZE_DAEMON_SESSION_IDLE_TIMEOUT_S=900 \
GAZE_SAFETY_NET_BACKEND=kiji-distilbert \
GAZE_KIJI_BACKEND=ort \
GAZE_KIJI_DISTILBERT_MODEL_DIR=/opt/kiji/model \
php artisan gaze:daemon:serve

# Ad-hoc override of the operational knobs (timeouts, caps, locale,
# NER threshold). CLI options win over config.
php artisan gaze:daemon:serve \
    --policy=/etc/gaze/policy.toml \
    --idle-timeout=1800 \
    --session-idle-timeout=900 \
    --session-cap=250 \
    --audit-db=/var/lib/gaze/audit.sqlite \
    --locale=de,en \
    --ner-threshold=0.8
```

Intentionally NOT shipped: `:start`, `:stop`, `:restart`, `:logs`. The
upstream daemon binary is a foreground stdio worker (`gaze daemon` has
no subverbs); inventing in-PHP supervision would conflict with adopter
supervisors. Use `systemctl stop`, `horizon:terminate`,
`supervisorctl stop` instead.

## Facade

Two entry shapes — pick the one that matches your call site.

### Composition (fluent sugar)

```php
use CertaMesh\Gaze\Facades\Gaze;

$session = Gaze::daemon()->session('agent-thread-a');

foreach ($turns as $turn) {
    $response = $session->clean($turn->text);
    $turn->cleanText = $response->cleanText;
}
```

`Gaze::daemon()` returns a `DaemonManager` request-scoped (Octane-safe).
`session($id)` returns a `DaemonSession` memoised per `$id` so repeated
lookups in an agent loop are allocation-free.

The daemon spawned on this path carries the **same full flag surface**
as `gaze:daemon:serve` — session tuning, NER overrides, and the
safety-net family are all forwarded from config (see
[Shared pipeline keys](#shared-pipeline-keys)); both paths delegate to
the shared `Daemon\DaemonArgv` assembler.

### Direct hot path (P5 agentic preservation)

```php
use CertaMesh\Gaze\Facades\Gaze;

$response = Gaze::daemon()->clean('agent-thread-a', $turn->text);
```

One PHP call = one JSONL line on the wire. Equivalent to the
composition chain but skips the intermediate `DaemonSession` allocation.
Prefer this when you have the session id in scope already and don't
need a long-lived `DaemonSession` handle.

## Session-id is a pseudonym-namespace boundary

> **One session-id per logical isolation boundary — per conversation, per
> tenant, per trust domain. Never reuse a single shared/global id across
> independent contexts.**

The `$id` you pass to `Gaze::daemon()->session($id)` /
`Gaze::daemon()->clean($id, $text)` is an **adopter-supplied string**, and
it does more than route a request: it keys the pseudonym counter namespace.
Every span cleaned under the same session-id draws from one shared counter
pool, so the *same* real value maps to the *same* token across every call
sharing that id.

That is exactly what you want **inside** one conversation (turn 4 can refer
to the entity minted in turn 1). It becomes a **linkability bug** the moment
the id spans contexts that should stay isolated: reuse one id across two
independent conversations — or two tenants — and their pseudonyms become
**cross-conversation linkable**. An observer who sees both transcripts can
re-link `PERSON_1` in conversation A to `PERSON_1` in conversation B. That
is a GDPR Art. 4(5) pseudonymization failure — data becomes re-linkable
across contexts it was supposed to be isolated from (upstream #277 / #275).

Rule of thumb:

- **One conversation / thread → one session-id.** Derive it from the
  conversation primary key, never a constant.
- **Multi-tenant → fold tenant identity into the id** (e.g.
  `tenant-{id}:conversation-{id}`) so two tenants can never share a
  namespace.
- **Never a global/app-wide constant** like `"default"` or `"gaze"` across
  unrelated requests.

See the [GDPR adopter guidance](../explanation/gdpr.md) for why
re-linkability is the central pseudonymization risk, and
[conversational-loops](./conversational-loops.md) for the sibling
"never sanitize once, trust forever" sharp edge.

## Error Variants

`Gaze::daemon()` calls throw an exception family rooted at
`GazeDaemonException extends GazeIntegrityException`. Variants are
exposed via `DaemonErrorVariant` (backed enum) so adopter `match()`
ladders can react to wire shapes individually. **Adopters MUST include a
`default` arm in any `match($variant)` block** — new wire variants land
in `DaemonErrorVariant::Unknown` and would otherwise raise an unhandled
`UnhandledMatchError`.

| Variant | Origin | Exception subclass | Hint |
|---|---|---|---|
| `JsonMalformed` | upstream | `GazeDaemonException` | Adapter framing bug. Open an issue. |
| `Pipeline` | upstream | `GazeDaemonException` | Upstream pipeline failed closed. Same fail-closed posture as one-shot. |
| `Transport` | adapter | `GazeDaemonTransportException` | Broken pipe / EOF / mismatched session id. Doctor probe is the only place reconnect logic lives — hot path is fail-closed. |
| `Timeout` | adapter | `GazeDaemonTimeoutException` | Per-request `gaze.daemon.request_timeout_ms` exceeded. Raise for cold first requests. |
| `Unavailable` | adapter | `GazeDaemonFeatureUnsupportedException` | Binary missing `daemon` subverb. Rebuild with `cargo install gaze-cli --features daemon`. |
| `Unknown` | forward-compat | `GazeDaemonException` | New upstream variant; doctor logs an adopter warning when it appears on `gaze daemon --help`. |

```php
use CertaMesh\Gaze\Daemon\DaemonErrorVariant;
use CertaMesh\Gaze\Exceptions\GazeDaemonException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTimeoutException;
use CertaMesh\Gaze\Exceptions\GazeDaemonTransportException;

try {
    $response = Gaze::daemon()->clean($sessionId, $text);
} catch (GazeDaemonTimeoutException $e) {
    // Queue back-pressure — retry with longer ceiling.
} catch (GazeDaemonTransportException $e) {
    // Fail-closed transport fault. Surface to ops; let supervisor restart.
} catch (GazeDaemonException $e) {
    match ($e->daemonVariant()) {
        DaemonErrorVariant::JsonMalformed => report_adapter_bug($e),
        DaemonErrorVariant::Pipeline      => surface_to_user($e),
        DaemonErrorVariant::Unavailable   => hint_rebuild($e),
        default                            => log_forward_compat($e), // REQUIRED
    };
}
```

`GazeDaemonException::toLogContext()` returns
`{daemon_variant, session_id, raw}` so structured logs carry the full
envelope without leaking `stderr_sha256` (daemon errors are stdout
envelopes — there is no stderr to hash).

The daemon exception family does **NOT** implement `Retryable`. Queue
retry policy is the adopter's responsibility — daemon failures map to
adopter-defined back-pressure (different surfaces have different
retry-vs-fail-fast semantics).

## Octane / Swoole / Concurrency

The shared stdio pipe is the headline trust risk: JSONL responses carry
no request id, so interleaved write/read between two concurrent callers
would mis-attribute responses across tenants (P4 trust contract).

Mitigations baked in:

1. **Request-scoped binding.** `DaemonClient` is bound via
   `app()->scoped()`. Each Octane request gets its own client and its
   own daemon subprocess — there is no cross-request stdio reuse.
2. **Per-request mutex.** `DaemonClient::request()` serialises calls
   within a request boundary via a `$busy` flag. Two fibers in the same
   request that both call `request()` will take turns; the second waits
   for the first to finish.
3. **`session_id` echo validation.** Every response's `session_id` is
   compared against the request's; mismatch throws
   `GazeDaemonTransportException` before the response leaves the client.

For Horizon fork-storms (N workers × 1 daemon binary), each worker
fork resolves a fresh `DaemonManager` via the container and spawns its
own `gaze daemon` subprocess. Adopters who want SQLite WAL-mode audit
contention should configure per-worker audit DB paths via tenant
identity.

## DaemonSession Serialization Boundary

```php
$session = Gaze::daemon()->session('agent-thread-a');
SomeJob::dispatch($session); // LogicException: DaemonSession is not serializable
```

`DaemonSession::__serialize()` throws `\LogicException`. The bound
`DaemonClient` is process-local — queueing a session would hand a
worker a stale handle to a daemon it never saw. Resolve a fresh
`Gaze::daemon()->session($id)` per worker tick instead.

## Eviction Wire Shape

When daemon sessions are evicted (LRU cap or
`--session-idle-timeout`), the upstream binary writes an audit-row with
`source = "daemon.session_eviction"`. The adapter does NOT expose a
PHP-side eviction event (deferred — first-adopter-ask). Tail-watchers
that need eviction observability today can subscribe to the audit DB:

```sql
SELECT session_id, source, occurred_at
FROM gaze_audit
WHERE source = 'daemon.session_eviction'
ORDER BY occurred_at DESC;
```

The schema is documented in upstream
[`docs/explanation/daemon/daemon-mode.md`](https://github.com/CertaMesh/gaze/blob/main/docs/explanation/daemon/daemon-mode.md).

## Doctor Probe

`php artisan gaze:doctor` skips the daemon section when
`gaze.daemon.policy_path` is null. When the key is populated, the
probe:

1. Pre-flights `gaze daemon --help` — feature-gate check. Missing
   subverb throws `GazeDaemonFeatureUnsupportedException` with the
   `cargo install gaze-cli --features daemon` hint.
2. Checks readability of `gaze.daemon.policy_path` and parent-dir
   writability of `gaze.daemon.audit_db_path` / `stderr_path` when set.
3. (`--deep`) Diffs the upstream variant list against
   `DaemonErrorVariant` cases. New variants surface as adopter warnings
   so you can upgrade typed-handling proactively.

## Test Helpers

`Gaze::fake()` extends to cover daemon calls:

```php
use CertaMesh\Gaze\Facades\Gaze;

Gaze::fake();

Gaze::daemon()->clean('agent-thread-a', 'hello world');

Gaze::assertDaemonCleaned('agent-thread-a');
Gaze::assertDaemonCleanCount(1);
Gaze::assertNothingDaemonCleaned(); // fails if any daemon call ran
```

No real binary is spawned; the fake handler returns a deterministic
`CleanResponse` so unit tests stay fast.

## Five-Axis Pitch

- **Reliability (P2).** Per-request timeout ceiling; fail-closed on EOF
  / broken pipe / mismatched session id; doctor probe surfaces new
  upstream variants forward-compat.
- **Reversibility (P4 sacrosanct).** Daemon is clean-only. Restore
  stays on the one-shot signed-blob contract. `DaemonSession::restore()`
  does NOT exist.
- **Agentic-first (P2).** Hot path explicit: `Gaze::daemon()->clean($id,
  $text)` = one PHP call = one JSONL line. Composition chain stays as
  fluent sugar.
- **Trust (P4).** Per-request mutex + scoped binding prevent
  cross-tenant payload leak through the shared stdio pipe; session_id
  echo validation catches any residual interleave.
- **Adopter ergonomics (P2).** One flat config block; one Facade method
  with two shapes; two artisan commands (foreground + diagnostic); one
  exception family with surface-distinct subclasses + enum-driven
  forward-compat. No connections boilerplate, no in-PHP daemon
  supervisor.

## See also

- [Upstream coverage matrix](../reference/upstream-coverage.md) — daemon
  command/flag/exception mapping.
- [Upstream `gaze daemon` spec](https://github.com/CertaMesh/gaze/blob/main/docs/explanation/daemon/daemon-mode.md) — JSONL protocol, eviction, graceful shutdown.
- [docs/upgrading.md](./upgrading.md) — v0.11.0 upgrade notes.

# Queue & Retry Guide

## Why `GazeRetryPolicy` Exists

`gaze` subprocess failures fall into four distinct categories with very different retry semantics:

| Category | Example | Should retry? |
|---|---|---|
| Caller bug | Empty input, input too large, invalid encoding | No — retrying the same input produces the same error |
| Config error | Invalid policy file, missing policy path | No — retrying without fixing config is pointless |
| Integrity error | Expired blob, invalid signature, unknown token | No — the session is permanently unrecoverable |
| Infra error | I/O failure, timeout, SIGPIPE | Yes — transient; retry with backoff |

Laravel's default queue retry behaviour (`$tries`, `$backoff`) applies uniformly to all exception types. `GazeRetryPolicy` implements the per-category logic so you do not need to replicate it in every job.

---

## Wiring `GazeRetryPolicy` Into a Job

```php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeIntegrityException;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\Queue\GazeRetryPolicy;

class RedactAndForwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 30, 60, 120, 300]; // seconds per attempt

    public function __construct(
        private readonly string $text,
    ) {}

    public function handle(): void
    {
        $session = Gaze::clean($this->text);

        // ... forward $session->cleanText to downstream service ...

        $restored = Gaze::restore($session, $responseFromDownstream);

        // ... use $restored ...
    }

    public function failed(\Throwable $e): void
    {
        // Log or notify as appropriate.
    }

    public function catch(\Throwable $e): void
    {
        if ($e instanceof GazeException) {
            if ($e instanceof GazeIntegrityException && $e->requiresFreshClean()) {
                // Blob is unrecoverable. Dispatch a fresh clean from original text.
                static::dispatch($this->text);
                $this->fail($e);
                return;
            }

            GazeRetryPolicy::dispatch($e, $this);
            return;
        }

        throw $e;
    }
}
```

> **Note:** `GazeRetryPolicy::dispatch()` requires the job to use `Queueable` and `InteractsWithQueue`. It checks for the `fail()` and `release()` methods at runtime and throws `\InvalidArgumentException` if they are absent.

---

## `RetryAction` Values

`GazeRetryPolicy::classify(\Throwable $e)` returns one of four `RetryAction` enum cases:

| Case | When | What `dispatch()` does |
|---|---|---|
| `RetryAction::Fail` | Exception implements `NonRetryable` | Calls `$job->fail($e)` — permanent failure |
| `RetryAction::ReleaseWithBackoff` | Exception implements `Retryable` (but not `RetryableWithAlert`) | Calls `$job->release($delay)` — re-queues with backoff |
| `RetryAction::ReleaseWithAlert` | Exception implements `RetryableWithAlert` | Fires `GazeInfraAlert` event, then calls `$job->release($delay)` |
| `RetryAction::Throw` | Exception does not implement any Gaze retry interface | Re-throws — not a Gaze exception |

**Variant-dependent exceptions:** before checking the marker interfaces above, `classify()` consults `CertaMesh\Gaze\Queue\Contracts\HasRetryDisposition` and returns `$e->retryDisposition()` directly. `GazeSafetyNetFailureException` uses this contract because its retry lane depends on the upstream safety-net `variant` sidecar (`Timeout` retries, `InputTooLarge` fails, `SuspectedLeak` alerts, unknown variants fail closed) — it implements none of the static markers. If you hand-roll an `instanceof` chain instead of calling `classify()`, add a `HasRetryDisposition` arm first.

### Backoff resolution

`GazeRetryPolicy` resolves the release delay from your job's `$backoff` property:

- `int $backoff` — used as-is for every attempt.
- `array $backoff` — indexed by `$job->attempts() - 1`; last value is used once the array is exhausted.
- Missing `$backoff` — falls back to 30 seconds.

---

## Exception Categories and Retry Behaviour

### Exit bucket 1 — Caller bug (`NonRetryable`)

| Exception | Trigger |
|---|---|
| `GazeEmptyInputException` | Input string is empty |
| `GazeInputTooLargeException` | Input exceeds `max_bytes` ceiling |
| `GazeInvalidEncodingException` | Input is not valid UTF-8 |
| `GazeStdinParseException` | Binary could not parse stdin JSON |

**Action:** `fail()`. Fix the input before re-dispatching. These exceptions also set `isCallerBug() === true` on the base `GazeException`.

### Exit bucket 2 — Config error (`NonRetryable`)

| Exception | Trigger |
|---|---|
| `GazePolicyConfigException` | TOML policy file is syntactically invalid |
| `GazePolicyConfigDetailException` | TOML policy file has a semantic validation error |
| `GazeAuditPurgeIso8601Exception` | `--before` timestamp is not valid ISO 8601 |
| `GazeAuditDbNotConfiguredException` | `gaze.audit_db_path` not set and no per-call override |
| `GazePolicyOpenException` | Binary cannot open the policy file |

**Action:** `fail()`. These are deployment or configuration problems. Fix `GAZE_POLICY_PATH` or `GAZE_AUDIT_DB_PATH` and re-deploy.

### Exit bucket 3 — Session integrity error

| Exception | Retry | `requiresFreshClean()` |
|---|---|---|
| `GazeUnknownTokenException` | `NonRetryable` → fail | No |
| `GazeResponseDecodeException` | `NonRetryable` → fail | No |
| `GazeInvalidSignatureException` | `NonRetryable` → fail | No |
| `GazeInvalidBlobVersionException` | `NonRetryable` → fail | **Yes** |
| `GazeBlobExpiredException` | `NonRetryable` → fail | **Yes** |
| `GazePipelineException` | `Retryable` → release | No |

`GazeBlobExpiredException` and `GazeInvalidBlobVersionException` both override `requiresFreshClean()` (defined on `GazeIntegrityException`) to return `true` — a signal that the only recovery is to re-run `Gaze::clean()` on the original plaintext. See the job example above. (Both also still carry the deprecated `Queue\Contracts\RequiresFreshClean` marker interface for BC until 1.0; prefer the method.)

`GazePipelineException` is retryable because it typically reflects transient SQLite contention on the audit DB. If it fires persistently, investigate write concurrency on the audit DB file.

### Exit bucket 4 / 141 — Infra error (`RetryableWithAlert`)

| Exception | Trigger |
|---|---|
| `GazeIoException` | Binary I/O failure (disk, pipe) |
| `GazeSigPipeException` | Binary killed by SIGPIPE (exit 141) |
| `GazeTimeoutException` | Binary exceeded `gaze.timeout_seconds` |

**Action:** `ReleaseWithAlert` — re-queue with backoff and fire a `GazeInfraAlert` event. Listen for this event to route to your alerting system:

```php
// In EventServiceProvider or using #[Listen]
use CertaMesh\Gaze\Events\GazeInfraAlert;

Event::listen(GazeInfraAlert::class, function (GazeInfraAlert $event) {
    // $event->throwable is the original exception
    \Log::error('Gaze infra failure', [
        'exception' => get_class($event->throwable),
        'message'   => $event->throwable->getMessage(),
    ]);
    // Notify your on-call channel here.
});
```

---

## Keeping Ciphertext Out of Telemetry

Session blobs are encrypted ciphertext. Even so, they should not appear in Telescope query logs, Pulse job payloads, or any telemetry that is stored longer than the session TTL.

### Laravel Telescope

Exclude Gaze job classes from Telescope's job watcher:

```php
// config/telescope.php
'ignore_jobs' => [
    \App\Jobs\RedactAndForwardJob::class,
],
```

Or globally prune session blob properties from all recorded jobs by filtering in a `TelescopeServiceProvider`:

```php
Telescope::filter(function (IncomingEntry $entry) {
    if ($entry->type === EntryType::JOB) {
        // Redact the serialized payload.
        unset($entry->content['payload']['data']['command']);
    }
    return true;
});
```

### Laravel Pulse

Pulse records job class names and statuses but not payloads by default. No special configuration is needed unless you have customised the `PulseJob` recorder.

---

## Failed Job Pruning Aligned to Session TTL

If a job fails after all retry attempts, Laravel stores it in the `failed_jobs` table. That record includes the serialized job payload — which contains the session blob ciphertext.

Prune failed jobs on a schedule that is no longer than your `GAZE_SESSION_TTL`:

```php
// In App\Console\Kernel (Laravel 10) or routes/console.php (Laravel 11+)
Schedule::command('queue:prune-failed --hours=48')->daily();
```

Align `--hours` to your TTL. If `GAZE_SESSION_TTL=7200` (2 hours), prune failed jobs after 2 hours at most so that a ciphertext sitting in `failed_jobs` cannot outlive the blob it protects.

Even after the TTL elapses, the ciphertext stored in `failed_jobs` remains encrypted under `GAZE_ENCRYPTION_KEY` (or `APP_KEY`). Prune as a defence-in-depth measure, not a sole safeguard.

---

## Summary Checklist

- [ ] Job uses `Queueable` and `InteractsWithQueue`.
- [ ] `catch(\Throwable $e)` checks for `GazeException` before calling `GazeRetryPolicy::dispatch($e, $this)`.
- [ ] `requiresFreshClean()` handled explicitly: dispatch a fresh job and fail the current one.
- [ ] `GazeInfraAlert` listener wired to your alerting system.
- [ ] Gaze job classes excluded from Telescope job recording or payload redacted.
- [ ] `queue:prune-failed` scheduled at an interval ≤ `GAZE_SESSION_TTL`.

# Getting Started with gaze-laravel

10-minute quickstart — install, configure, first clean/restore cycle, one test.

For the full API reference see [README.md](../../README.md).

## 1. Install

```bash
composer require certamesh/gaze-laravel
php artisan gaze:install
```

`gaze:install` is the canonical setup path — it downloads the `gaze` binary,
publishes the config, writes a default `policy.toml`, and finishes on a
`gaze:doctor` green-check. It is idempotent, so it's safe to re-run.

## 2. Configure

Add to `.env`:

```env
GAZE_POLICY_PATH=/absolute/path/to/policy.toml
# GAZE_ENCRYPTION_KEY=base64:<32-byte-key>   # optional; falls back to APP_KEY
```

## 3. First clean/restore cycle

```php
use CertaMesh\Gaze\Facades\Gaze;

class EmailDraftController extends Controller
{
    public function draft(Request $request): JsonResponse
    {
        $session = Gaze::clean($request->input('body'));
        // $session->cleanText is safe for your LLM — real PII replaced with tokens

        $llmReply = $this->callLlm($session->cleanText);

        $draft = Gaze::restore($session, $llmReply);
        // restore() maps tokens back to the original values from the encrypted blob

        return response()->json(['draft' => $draft]);
    }
}
```

> **Note — pass a `string`, not a `Stringable`.** `clean(string $text)` takes a
> native `string`, but `$request->string('body')` returns an
> `Illuminate\Support\Stringable` object — under a `declare(strict_types=1)`
> caller PHP will not coerce it, and the call fatals with a `TypeError`. Use
> `$request->input('body')` as above, or cast explicitly:
> `(string) $request->string('body')`.

Before you forward `$session->cleanText`, you can also ask the session how much
it trusts its own redaction: `$session->coverageState()` returns upstream's
coverage verdict (`Verified` / `Unverified` / `Suspect`) and
`$session->hasSuspectedLeak()` flags a possible bleed-through — see the
[security model](../explanation/security.md) and the trust check in
[`examples/clean-before-openai.php`](../../examples/clean-before-openai.php).

→ [Blob lifecycle, Livewire patterns, queue jobs, conversational-loop guidance](../../README.md#blob-lifecycle)

> **Heads up — one session-id per isolation boundary.** Once you move beyond
> a single request into multi-turn or multi-tenant flows, give each
> conversation / tenant / trust domain its **own** session-id; never reuse a
> shared or global id across independent contexts. The id keys the pseudonym
> namespace, so reusing it makes tokens linkable across conversations — a
> GDPR Art. 4(5) failure. Internalize this before you design session scoping:
> [daemon § Session-id is a pseudonym-namespace boundary](../how-to/daemon.md#session-id-is-a-pseudonym-namespace-boundary).

## 4. Test with fakes

```php
use CertaMesh\Gaze\Facades\Gaze;

it('strips PII and restores the draft', function () {
    Gaze::fake();

    $this->postJson('/email/draft', ['body' => 'Call bob@example.invalid'])->assertOk();

    Gaze::assertCleaned('Call bob@example.invalid');
    Gaze::assertRestored();
});
```

→ [Full fake API and assert methods](../../README.md#testing)

## Next steps

- [Config reference](../../README.md#config) — all env keys and defaults
- [Exceptions](../../README.md#exceptions) — exit-bucket taxonomy
- [Operations](../../README.md#operations) — `gaze:check`, `gaze:doctor`, `gaze:bench`
- [Security model](../../README.md#security-model) — what the adapter guarantees
- [Enabling NER](../../README.md#enabling-ner) — multilingual / higher-recall detection
- [Audit surface](../how-to/audit-query-export.md) — purge workflow and upcoming query/export verbs

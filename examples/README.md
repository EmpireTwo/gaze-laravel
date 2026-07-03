# Examples

Runnable, copy-paste starting points for `gaze-laravel`. Every example follows
the same rule: **only `$session->cleanText` crosses the model boundary; the
`GazeSession` (encrypted `ciphertext` + the token↔real-value `entries`) stays
server-side, and `Gaze::restore()` is owner-side.** Detection lives upstream in
the [`gaze`](https://github.com/CertaMesh/gaze) binary — this package only
exposes it through Laravel surfaces (Facade, artisan, config).

| Example | What it shows |
| --- | --- |
| [`clean-before-openai.php`](./clean-before-openai.php) | **(Primary)** the core `clean` → trust check → send `cleanText` → `restore` round-trip before an OpenAI-style call. Gates on `$session->hasSuspectedLeak()` / `coverageState()` — upstream's own coverage verdict — before anything crosses the boundary. |
| [`queued-job-clean.php`](./queued-job-clean.php) | the same round-trip inside a `ShouldQueue` Job — agentic-first, one session per unit of work, no plaintext persisted. |
| [`livewire-chat.php`](./livewire-chat.php) | multi-turn chat that keeps the `GazeSession` out of Livewire wire state. |

## How to run

These are illustrative scripts. The stubbed LLM (`fakeOpenAi` / `askLlm` /
`askAssistant`) stands in for your real provider — no network call is made. The
real `Gaze::clean()` / `Gaze::restore()` calls need the `gaze` binary installed
and resolve through the package's service container, so they run inside a booted
Laravel app (e.g. `php artisan tinker`, a route, a queued job, or a Livewire
component) rather than as bare `php file.php` scripts.

```php
use CertaMesh\Gaze\Facades\Gaze;

$session = Gaze::clean($prompt);          // -> GazeSession
$reply   = yourLlm($session->cleanText);  // only cleanText leaves your server
$final   = Gaze::restore($session, $reply);
```

The only real Gaze verbs are `clean`, `restore`, `audit()`, and `daemon()`.
Stub the LLM, never Gaze.

See [`../docs/`](../docs/) for installation, the daemon, and the full Facade
surface.

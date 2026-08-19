# gaze-laravel

[![Latest Stable Version](https://img.shields.io/packagist/v/certamesh/gaze-laravel.svg?style=flat-square)](https://packagist.org/packages/certamesh/gaze-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/certamesh/gaze-laravel.svg?style=flat-square)](https://packagist.org/packages/certamesh/gaze-laravel)
![PHP Version](https://img.shields.io/badge/PHP-%5E8.2-777BB4?style=flat-square&logo=php&logoColor=white)
![Laravel Version](https://img.shields.io/badge/Laravel-11%20%7C%7C%2012%20%7C%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Tests](https://img.shields.io/github/actions/workflow/status/CertaMesh/gaze-laravel/test.yml?branch=main&label=tests&style=flat-square)](https://github.com/CertaMesh/gaze-laravel/actions/workflows/test.yml)
[![License](https://img.shields.io/github/license/CertaMesh/gaze-laravel?style=flat-square)](https://github.com/CertaMesh/gaze-laravel/blob/main/LICENSE)

> Pseudonymize PII / PHI / secrets before they cross the LLM boundary — one `Gaze::clean()` call out, `Gaze::restore()` back, fully reversible owner-side.

```php
use CertaMesh\Gaze\Facades\Gaze;

// 1. Strip PII / PHI / secrets before the prompt leaves your app.
$session = Gaze::clean($request->input('body'));

// 2. Send only pseudonymized text across the model boundary.
$reply = $llm->complete($session->cleanText);

// 3. Restore the real values owner-side, once the model has replied.
return Gaze::restore($session, $reply);
```

The model never sees real data, yet your app restores it losslessly — tokens map
back through a signed, encrypted-at-rest session blob. The same call runs inside
queues and long-lived agent loops, not just a single HTTP request, and subprocess
failures arrive as typed, exit-bucketed exceptions.

`gaze-laravel` is the Laravel adapter for the [`gaze`](https://github.com/CertaMesh/gaze)
CLI contract. Detection logic lives upstream in Rust — this package never
re-implements pseudonymization in PHP.

## Contents

- [Requirements](#requirements)
- [Installation](#installation) — **start here**
- [Usage](#usage)
- [How is this different?](#how-is-this-different-from-regex--generic-anonymization-libraries)
- [Advanced surfaces](#advanced-surfaces)
- [Documentation](#documentation)
- [Security](#security)

## Requirements

- PHP `^8.2`
- Laravel `^11.0 || ^12.0 || ^13.0`
- The `gaze` binary — `gaze:install` fetches the pinned build for you (see below),
  or supply your own on `PATH`, in `vendor/bin/gaze`, or via `GAZE_BINARY`.

## Installation

Two steps:

```bash
composer require certamesh/gaze-laravel
php artisan gaze:install
```

`php artisan gaze:install` is the canonical setup path. It provisions the app
end-to-end and finishes on a `gaze:doctor` green-check:

- downloads the **pinned gaze binary** into `vendor/bin/`,
- publishes the **config** and writes a sane default **`policy.toml`** (never
  clobbering one you've already edited),
- optionally installs the **NER model** (~184 MB, ONNX-backed),
- optionally wires a **safety-net backend** (OPF or Kiji).

It is idempotent — safe to re-run. A failed run rolls `.env` back to its
pre-install state.

### Non-interactive / CI

Headless runs skip every prompt; the safety-net defaults to `none` unless you
opt in:

```bash
php artisan gaze:install --no-interaction --safety-net=opf
```

Common flags (`php artisan gaze:install --help` lists them all):

| Flag | Effect |
| --- | --- |
| `--skip-binary` | Don't download the gaze binary |
| `--skip-ner` | Don't download the NER model |
| `--safety-net=opf\|kiji\|none` | Pick the safety-net backend non-interactively |
| `--force` | Re-run already-done steps (re-download binary, re-fetch NER) |
| `--force-policy` | Also overwrite an existing `policy.toml` (destructive — off by default) |
| `--no-doctor` | Skip the final `gaze:doctor` gate |

### Sub-commands

The umbrella composes three standalone commands you can run on their own for
finer control:

- `gaze:install:binary` — install the pinned gaze binary into `vendor/bin/`.
- `gaze:install:ner` — download the pinned ONNX NER model and wire `policy.toml`
  (legacy alias: `gaze:install-ner`).
- `gaze:install:safety-net` — wire an `opf` or `kiji` backend into `.env`.

### Automating the binary install (optional)

`gaze:install` is explicit by design — nothing runs on `composer install` or
`composer update`. Adopters who want the old auto-download behaviour back (the
binary refreshed after every Composer update, no plugin) can wire the
Composer-context installer into a `post-update-cmd` script in their app's
`composer.json`:

```json
"scripts": {
    "post-update-cmd": [
        "CertaMesh\\Gaze\\Install\\BinaryInstaller::postInstall"
    ]
}
```

Set `GAZE_SKIP_BINARY_DOWNLOAD=1` to suppress that fetch (e.g. in CI, where the
binary is provisioned separately). The `GAZE_VERSION` / `GAZE_RELEASE_BASE`
overrides and the full config reference live in
[Configuration](./docs/reference/configuration.md).

**New here?** Walk through the [getting started guide](./docs/tutorials/getting-started.md).

## Usage

```php
use CertaMesh\Gaze\Facades\Gaze;

$session = Gaze::clean($request->input('body'));
$reply   = $llm->complete($session->cleanText);

return Gaze::restore($session, $reply);
```

See [`examples/clean-before-openai.php`](./examples/clean-before-openai.php) for a
runnable clean → OpenAI → restore example.

### Per-rule detection entries

`GazeSession::$entries` exposes each tokenized span as a readonly `Entry` DTO
(`class`, `raw`, `token`, `family`). The array is empty for upstream releases
that don't surface the field, so consumers can always iterate safely:

```php
foreach ($session->entries as $entry) {
    logger()->info('detected', [
        'class' => $entry->class,
        'token' => $entry->token,
        'family' => $entry->family,
    ]);
}
```

### What the binary actually resolved

The session also carries the rest of upstream's `stats` object, which is the
quickest way to confirm your locale chain and rulepack dictionaries reached
detection instead of silently falling back to defaults:

```php
$session->activeLocale();       // 'de-DE' — head of the resolved chain
$session->localeChain;          // ['de-DE', 'en'] — priority ordered
$session->dictionariesLoaded;   // list<LoadedDictionary>: name, termCount, source
```

Metadata only — no dictionary terms and no source text cross the surface. See
[upstream coverage § Clean stats projection](./docs/reference/upstream-coverage.md#clean-stats-projection-v0120).

See [Exceptions](./docs/reference/exceptions.md) for the exit-bucket reference and
[Testing](./docs/how-to/testing.md) for fakes, assertions, and integration setup.

## How is this different from regex / generic anonymization libraries?

| | Regex / generic anonymization libs | `gaze-laravel` |
| --- | --- | --- |
| **Detection** | Hand-maintained PHP regex, usually English-centric | Upstream Rust `gaze` — NER + validated rulepacks + locale packs, never re-implemented in PHP |
| **Reversibility** | One-way redaction; the original is gone | Tokens map back owner-side through a signed, encrypted-at-rest session blob |
| **Failures** | Generic exceptions or silent pass-through | Typed exceptions bucketed by exit class (caller / config / integrity / infra) |
| **Runtime fit** | Built for a single HTTP request | Queue-aware, with a long-lived daemon for multi-turn agent loops |

**Not what you're after?** This is *not* in-process PHP detection — the Rust crate
is the source of truth — and *not* a generic subprocess wrapper; it is specific to
`gaze`. See the [North Star non-goals](./docs/NORTH_STAR.md#non-goals).

## Advanced surfaces

Opt-in surfaces — reach for them once the basic clean / restore round-trip is in place:

- **[HTTP proxy daemon](./docs/how-to/proxy-daemon.md)** — pseudonymizes requests
  bound for OpenAI / Anthropic / Gemini and restores their replies.
- **[JSONL stdio daemon](./docs/how-to/daemon.md)** — low-latency `Gaze::daemon()`
  runtime for agent loops and worker queues, with no per-turn binary startup.
- **[Kiji safety-net backend](./docs/how-to/safety-net.md)** — Tier 2.5 DistilBERT
  NER subprocess for higher-recall Pass-3 leak detection.

## Documentation

- [Documentation index](./docs/README.md)
- [Getting started](./docs/tutorials/getting-started.md)
- [Configuration](./docs/reference/configuration.md)
- [Architecture](./docs/explanation/architecture.md)
- [NER detection](./docs/explanation/ner.md)
- [Blob lifecycle](./docs/explanation/blob-lifecycle.md)
- [Security model](./docs/explanation/security.md)
- [Exceptions](./docs/reference/exceptions.md)
- [Diagnostics](./docs/reference/diagnostics.md)
- [Testing](./docs/how-to/testing.md)
- [Queue integration](./docs/how-to/queue-integration.md)
- [Retry discipline](./docs/how-to/retry.md)
- [Livewire integration](./docs/how-to/livewire-integration.md)
- [Conversational-loop patterns](./docs/how-to/conversational-loops.md)
- [Operations](./docs/how-to/operations.md)
- [Audit query / export](./docs/how-to/audit-query-export.md)
- [Proxy daemon](./docs/how-to/proxy-daemon.md)
- [Daemon (JSONL stdio)](./docs/how-to/daemon.md)
- [SafetyNet (OPF + Kiji)](./docs/how-to/safety-net.md)
- [Upgrading & migrations](./UPGRADING.md)
- [Upstream coverage](./docs/reference/upstream-coverage.md)

## Security

Session blobs are encrypted at rest with Laravel's encrypter, keyed by
`GAZE_ENCRYPTION_KEY` or `APP_KEY`. Only the pseudonymized `$session->cleanText`
should cross the model boundary; restore happens owner-side. See the
[Security model](./docs/explanation/security.md) for guarantees, responsibilities,
and compliance boundaries.

## Known limitations

- Pre-built binaries currently cover Linux x86_64 and macOS arm64.
  Intel Mac users must install `gaze` from source and set `GAZE_BINARY`.
- NER model artifacts are not bundled in the Composer package. `gaze:install`
  fetches them on demand (or run `gaze:install:ner` directly); pass `--skip-ner`
  to defer.

## License

Apache-2.0 — see [LICENSE](./LICENSE).

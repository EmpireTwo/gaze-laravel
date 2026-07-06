# AGENTS.md

This file provides guidance for AI agents (Claude Code, Codex, Cursor, etc.) when working with code in this repository.

`gaze-laravel` is a Laravel adapter for the Gaze pseudonymization engine.

## Mission

`gaze-laravel` is the **PHP gate of [`gaze`](https://github.com/CertaMesh/gaze)**. When upstream `gaze` ships a feature, this package exposes it via idiomatic Laravel surfaces — Facade methods, artisan commands, config keys — when it makes sense in PHP. Detection logic stays upstream; this package never re-implements pseudonymization in PHP.

Project compass: [`docs/NORTH_STAR.md`](./docs/NORTH_STAR.md) — mission, principles, non-goals, SemVer policy. Cite it when implementation choices fork.

Living roadmap: Solo scratchpad `Convention — Living roadmap protocol` (41) → `gaze → gaze-laravel coverage roadmap` (46). Orchestrators maintain on every release. (IDs 1550/1538 in older docs are stale.)

Operational implications for agents working in this repo:
- **Track upstream first.** Before adding a feature here, verify the upstream `gaze` contract (CLI flags, exit codes, JSON shapes). The source of truth is the Rust repo, not this one.
- **Map predictably.** New gaze CLI flags map to `config/gaze.php` keys; new subcommands map to artisan commands (`php artisan gaze:*`); new runtime concepts map to `CertaMesh\Gaze\Gaze` Facade methods or chainable builders. Hold the convention so the surface is guessable. Example (v0.11.0): upstream `gaze daemon` JSONL runtime → `Gaze::daemon()` Facade + `gaze.daemon.*` config + `gaze:daemon:serve|status` artisans. Long-lived stdio worker, so the Facade returns a `DaemonManager` you can compose (`->session($id)->clean($text)`) OR call directly (`->clean($id, $text)`) — both legs land on one JSONL line per call.
- **Don't extend semantics.** If a feature doesn't exist upstream, push it to the gaze repo first, not here. Filter "does it make sense in PHP/Laravel" — some Rust-native concepts (e.g. async streaming primitives) won't translate; document the gap rather than fake the surface.
- **Lockstep on releases.** Each gaze release triggers an audit: which new features need a PHP wrap? What's the minimum gaze version `gaze-laravel` is currently compatible with? Bump compat windows explicitly in `composer.json` and `CHANGELOG.md`.

## Tooling preference

Standard shell tools (`rg`, `git`, `bash`, `jq`) are preferred for repo-inspection commands. If your AI environment provides a context-compression layer (e.g. lean-ctx), use it when available — otherwise plain shell is fine.

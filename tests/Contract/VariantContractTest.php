<?php

declare(strict_types=1);

use CertaMesh\Gaze\Variant;

/**
 * Source-of-truth fixture mirrored from upstream `crates/gaze-cli/src/error.rs`,
 * re-verified against gaze v0.12.0 (the pinned release). Each row pins one
 * upstream `CliError` variant:
 *   - 0: enum case name on the PHP side
 *   - 1: exit bucket the upstream binary returns (`exit_code()`)
 *   - 2: minimal `{error, exit, ...}` JSON shape upstream emits on stderr
 *
 * Element 2's `error` field is the variant_name() upstream actually writes — note
 * the collapse where `PolicyConfig` and `PolicyConfigDetail` share the same wire
 * name and are disambiguated only by presence of the `detail` sidecar. The sidecar
 * fields on `AuditPurgeIso8601` (`input`) and `UnknownToken` (`token`) are also
 * preserved so we round-trip the realistic shape rather than a stripped one.
 *
 * The dataset key is the case name so test failure messages print the variant
 * (`with data set "PolicyConfigDetail"`) instead of an opaque positional index.
 *
 * Two deliberate asymmetries with upstream's variant_name() set, both re-checked
 * at the v0.12.0 pin:
 *
 *  - `SigPipe` is NOT an upstream wire variant — no upstream source emits
 *    `{"error":"SigPipe"}`. It is the adapter's exit-141 signal mapping
 *    (`Variant::unknownFor()`), for a binary killed by SIGPIPE that never got to
 *    write an envelope. Don't go looking for it in error.rs; the row here pins
 *    the mapping, not an upstream payload.
 *  - Upstream also emits `Setup`, `Document`, `Mcp` and `Proxy` (from the
 *    feature-gated `gaze setup` / `document` / `mcp` / `proxy` subcommands).
 *    They are absent by design: the adapter never invokes those subcommands on
 *    a path that parses a stderr envelope — the deferred surfaces are unwrapped,
 *    and the `gaze:proxy:*` artisans stream the process and return its exit code
 *    rather than mapping a typed exception. Wrapping any of those subcommands is
 *    the trigger to add its variant here.
 */
const UPSTREAM_VARIANTS = [
    'StdinParse' => ['StdinParse', 1, ['error' => 'StdinParse', 'exit' => 1]],
    'EmptyInput' => ['EmptyInput', 1, ['error' => 'EmptyInput', 'exit' => 1]],
    'InputTooLarge' => ['InputTooLarge', 1, ['error' => 'InputTooLarge', 'exit' => 1]],
    'InvalidEncoding' => ['InvalidEncoding', 1, ['error' => 'InvalidEncoding', 'exit' => 1]],
    'PolicyConfig' => ['PolicyConfig', 2, ['error' => 'PolicyConfig', 'exit' => 2]],
    'PolicyConfigDetail' => ['PolicyConfigDetail', 2, ['error' => 'PolicyConfig', 'exit' => 2, 'detail' => 'unknown key `[[detector]]`']],
    'PolicySchemaUnsupported' => ['PolicySchemaUnsupported', 2, ['error' => 'PolicySchemaUnsupported', 'exit' => 2, 'found' => '9.9.0', 'supported' => '0.1']],
    'SafetyNetConfig' => ['SafetyNetConfig', 3, ['error' => 'SafetyNetConfig', 'exit' => 3, 'detail' => 'openai filter config missing']],
    'SafetyNet' => ['SafetyNet', 3, ['error' => 'SafetyNet', 'exit' => 3, 'variant' => 'Timeout']],
    'SafetyNetArtifactMissing' => ['SafetyNetArtifactMissing', 2, ['error' => 'SafetyNetArtifactMissing', 'exit' => 2, 'backend' => 'kiji-distilbert', 'path' => '/var/lib/gaze/models/kiji']],
    'AuditPurgeIso8601' => ['AuditPurgeIso8601', 2, ['error' => 'AuditPurgeIso8601', 'exit' => 2, 'input' => 'not-a-date']],
    'UnknownToken' => ['UnknownToken', 3, ['error' => 'UnknownToken', 'exit' => 3, 'token' => 'gz1_abc']],
    'UnsupportedSessionScope' => ['UnsupportedSessionScope', 3, ['error' => 'UnsupportedSessionScope', 'exit' => 3, 'variant' => 'global']],
    'InvalidSignature' => ['InvalidSignature', 3, ['error' => 'InvalidSignature', 'exit' => 3]],
    'InvalidBlobVersion' => ['InvalidBlobVersion', 3, ['error' => 'InvalidBlobVersion', 'exit' => 3]],
    'BlobExpired' => ['BlobExpired', 3, ['error' => 'BlobExpired', 'exit' => 3]],
    'Pipeline' => ['Pipeline', 3, ['error' => 'Pipeline', 'exit' => 3]],
    'Io' => ['Io', 4, ['error' => 'Io', 'exit' => 4]],
    'SigPipe' => ['SigPipe', 141, ['error' => 'SigPipe', 'exit' => 141]],
    'PolicyOpen' => ['PolicyOpen', 4, ['error' => 'PolicyOpen', 'exit' => 4]],
];

it('upstream variant exists as a PHP enum case', function (string $name, int $exit, array $wirePayload) {
    $cases = array_map(fn (Variant $v) => $v->name, Variant::cases());

    expect($cases)->toContain($name);
})->with(UPSTREAM_VARIANTS);

it('PHP exit bucket matches upstream exit code', function (string $name, int $exit, array $wirePayload) {
    $variant = constant(Variant::class.'::'.$name);

    expect($variant->exitBucket())->toBe($exit);
})->with(UPSTREAM_VARIANTS);

it('tryFromStderr round-trips the upstream wire shape', function (string $name, int $exit, array $wirePayload) {
    $stderr = json_encode($wirePayload, JSON_THROW_ON_ERROR);
    $expected = constant(Variant::class.'::'.$name);

    expect(Variant::tryFromStderr($stderr, $exit))->toBe($expected);
})->with(UPSTREAM_VARIANTS);

it('PHP enum has no variants beyond the upstream set (catches reverse drift)', function () {
    $expectedNames = array_map(fn (array $row) => $row[0], array_values(UPSTREAM_VARIANTS));
    $actualNames = array_map(fn (Variant $v) => $v->name, Variant::cases());

    expect(array_diff($actualNames, $expectedNames))->toBe([]);
});

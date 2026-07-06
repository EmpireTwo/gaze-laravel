<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

/**
 * Shared deterministic masking for the Gaze test doubles.
 *
 * The single source of the fake token grammar: both the one-shot
 * `FakeGaze::clean()` path and the daemon `FakeDaemonManager::clean()`
 * path delegate here, so `Gaze::fake()` masks identically no matter
 * which surface an adopter exercises. No detection logic lives in PHP —
 * this only produces a predictable masked *shape* for assertions.
 *
 * Grammar (mirrors the real binary's token classes):
 *  - wrapped class tokens (`<Email_1>`, `<Name_2>`, …) normalize to `<Name_1>`
 *  - wrapped custom tokens (`<Custom:order_id_9>`) normalize to `<Custom:order_id_1>`
 *  - bare tokens (`name_2`, `custom:sku_3`) keep their bare/lowercase shape
 *  - format-preserving fake emails (`email2@example.test`) stay format-preserving
 *  - real email addresses mask to `<Email_1>`
 *  - the literal `Alice` masks to `Name_1` as a last-resort name fallback
 *
 * @internal test-support detail of `FakeGaze` / `FakeDaemonManager`; the
 *           public seams are those fakes and the `Gaze` facade assertions.
 */
final class FakeTokenizer
{
    /**
     * The alternation branches of the token grammar, in match-priority
     * order: token-shaped alternatives first (so `email1@example.test`
     * hits its format-preserving branch before the generic email branch
     * at the end can claim it), specific class lists before the generic
     * catch-alls. Composed into the final pattern by {@see pattern()}.
     *
     * @var list<string>
     */
    private const TOKEN_BRANCHES = [
        // Wrapped known-class tokens: <Email_1>, <Name_2>, <Location_3>, <Organization_4>
        '<(?:Email|Name|Location|Organization)_\d+>',
        // Wrapped custom tokens: <Custom:order_id_9>
        '<Custom:[a-z0-9_]*_\d+>',
        // Bare known-class tokens: email_1, name_2, location_3, organization_4
        '\b(?:email|name|location|organization)_\d+\b',
        // Bare custom tokens: custom:sku_3
        '\bcustom:[a-z0-9_]*_\d+\b',
        // Format-preserving fake emails: email2@example.test
        '\bemail\d+@example\.test\b',
        // Wrapped generic uppercase-class tokens: <Phone_3>
        '<[A-Z][a-zA-Z]+_\d+>',
        // Wrapped generic lowercase-class tokens: <phone_number_3>
        '<[a-z][a-z_]+_\d+>',
        // Bare generic uppercase tokens: Phone_3
        '\b[A-Z][a-zA-Z]+_\d+\b',
        // Bare generic lowercase tokens: phone_number_3
        '\b[a-z][a-z_]+_\d+\b',
        // Real email addresses (last resort — every token shape above wins first)
        '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}',
    ];

    private static function pattern(): string
    {
        return '/'.implode('|', self::TOKEN_BRANCHES).'/';
    }

    public static function mask(string $text): string
    {
        $cleanText = preg_replace_callback(
            self::pattern(),
            static function (array $match): string {
                $token = $match[0];

                if (preg_match('/^email\d+@example\.test$/', $token) === 1) {
                    return 'email1@example.test';
                }

                if (str_contains($token, '@')) {
                    return '<Email_1>';
                }

                if (str_starts_with($token, '<Custom:')) {
                    return '<Custom:order_id_1>';
                }

                if (str_starts_with($token, 'custom:')) {
                    return 'custom:order_id_1';
                }

                if (str_starts_with($token, '<')) {
                    return ctype_lower($token[1]) ? '<name_1>' : '<Name_1>';
                }

                return ctype_lower($token[0]) ? 'name_1' : 'Name_1';
            },
            $text,
        );

        if (! is_string($cleanText) || $cleanText === $text) {
            return str_replace('Alice', 'Name_1', $text);
        }

        return $cleanText;
    }
}

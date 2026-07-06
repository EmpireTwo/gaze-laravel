<?php

declare(strict_types=1);

use CertaMesh\Gaze\Testing\FakeTokenizer;

/*
 * Per-branch parity dataset for the fake token grammar. One case (at least)
 * per alternation branch, plus the ordering-sensitive overlaps, so the
 * grammar can be restructured (e.g. recomposed from named parts) without
 * any observable behavior change slipping through.
 */
it('masks each token-grammar branch to its normalized shape', function (string $input, string $expected) {
    expect(FakeTokenizer::mask($input))->toBe($expected);
})->with([
    // Branch: wrapped known-class tokens
    'wrapped email token' => ['Send <Email_4> now', 'Send <Name_1> now'],
    'wrapped name token' => ['Hi <Name_2>', 'Hi <Name_1>'],
    'wrapped location token' => ['At <Location_7> today', 'At <Name_1> today'],
    'wrapped organization token' => ['From <Organization_12>', 'From <Name_1>'],

    // Branch: wrapped custom tokens
    'wrapped custom token' => ['Order <Custom:order_id_9> shipped', 'Order <Custom:order_id_1> shipped'],
    'wrapped custom token other slug' => ['SKU <Custom:sku_3>', 'SKU <Custom:order_id_1>'],

    // Branch: bare known-class tokens
    'bare email token' => ['ref email_3 attached', 'ref name_1 attached'],
    'bare name token' => ['ref name_7 attached', 'ref name_1 attached'],
    'bare location token' => ['ref location_2', 'ref name_1'],
    'bare organization token' => ['ref organization_5', 'ref name_1'],

    // Branch: bare custom tokens
    'bare custom token' => ['see custom:sku_2', 'see custom:order_id_1'],

    // Branch: format-preserving fake emails (must win over the generic
    // email branch listed last)
    'format-preserving email' => ['Mail email7@example.test now', 'Mail email1@example.test now'],

    // Branch: wrapped generic class tokens (uppercase and lowercase)
    'wrapped generic uppercase token' => ['See <Phone_3>', 'See <Name_1>'],
    'wrapped generic lowercase token' => ['See <phone_number_3>', 'See <name_1>'],

    // Branch: bare generic tokens (uppercase and lowercase)
    'bare generic uppercase token' => ['See Phone_3', 'See Name_1'],
    'bare generic lowercase token' => ['See phone_number_3', 'See name_1'],

    // Branch: real email addresses
    'real email address' => ['Reach bob@example.invalid today', 'Reach <Email_1> today'],
    'real email with plus tag' => ['Cc test+tag@corp.invalid', 'Cc <Email_1>'],

    // Fallback: literal Alice only when no branch matched at all
    'name fallback' => ['Hello Alice', 'Hello Name_1'],
    'no fallback when a token matched' => ['Alice sent <Email_1>', 'Alice sent <Name_1>'],
    'passthrough when nothing matches' => ['Nothing sensitive here', 'Nothing sensitive here'],

    // Ordering overlaps across branches in one input
    'mixed classes' => [
        'Alice <Organization_3> bob@example.invalid custom:sku_2 email2@example.test',
        'Alice <Name_1> <Email_1> custom:order_id_1 email1@example.test',
    ],
]);

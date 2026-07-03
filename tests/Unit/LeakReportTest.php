<?php

declare(strict_types=1);

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\LeakReport;
use CertaMesh\Gaze\LeakSuspect;

/**
 * Representative upstream `leak_report` shapes. Mirrors gaze v0.11.3
 * `LeakReportResponse` (crates/gaze-cli/src/pipeline/run.rs):
 *   { stats: {suspect_count, uncovered_count, partial_bleed_count,
 *             class_mismatch_count, locale_skipped_count},
 *     suspects: [LeakSuspectResponse...], telemetry: [...], replay_hash? }
 *
 * @param  array<string, int>  $stats
 * @param  list<array<string, mixed>>  $suspects
 * @return array<string, mixed>
 */
function leakReportArray(array $stats = [], array $suspects = [], ?string $replayHash = null): array
{
    $report = [
        'stats' => array_merge([
            'suspect_count' => 0,
            'uncovered_count' => 0,
            'partial_bleed_count' => 0,
            'class_mismatch_count' => 0,
            'locale_skipped_count' => 0,
        ], $stats),
        'suspects' => $suspects,
        'telemetry' => [],
    ];

    if ($replayHash !== null) {
        $report['replay_hash'] = $replayHash;
    }

    return $report;
}

it('parses the leak_report stats counts from the upstream shape', function () {
    $report = LeakReport::fromArray(leakReportArray([
        'suspect_count' => 2,
        'uncovered_count' => 3,
        'partial_bleed_count' => 4,
        'class_mismatch_count' => 5,
        'locale_skipped_count' => 6,
    ]));

    expect($report->suspectCount)->toBe(2)
        ->and($report->uncoveredCount)->toBe(3)
        ->and($report->partialBleedCount)->toBe(4)
        ->and($report->classMismatchCount)->toBe(5)
        ->and($report->localeSkippedCount)->toBe(6);
});

it('parses suspects into LeakSuspect metadata DTOs', function () {
    $report = LeakReport::fromArray(leakReportArray(
        ['suspect_count' => 1, 'class_mismatch_count' => 1],
        [[
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'raw_label' => 'private_person',
            'mapped_class' => 'Name',
            'leak_kind' => 'class_mismatch',
            'pipeline_class' => 'Email',
            'span_len' => 17,
            'field_path' => 'body.note',
            'score' => 0.92,
        ]],
    ));

    expect($report->suspects)->toHaveCount(1)
        ->and($report->suspects[0])->toBeInstanceOf(LeakSuspect::class)
        ->and($report->suspects[0]->safetyNetId)->toBe('openai-privacy-filter-subprocess')
        ->and($report->suspects[0]->rawLabel)->toBe('private_person')
        ->and($report->suspects[0]->mappedClass)->toBe('Name')
        ->and($report->suspects[0]->leakKind)->toBe('class_mismatch')
        ->and($report->suspects[0]->pipelineClass)->toBe('Email')
        ->and($report->suspects[0]->spanLen)->toBe(17)
        ->and($report->suspects[0]->fieldPath)->toBe('body.note')
        ->and($report->suspects[0]->score)->toBe(0.92);
});

it('defaults to zero counts and empty suspects on absent or malformed fields', function () {
    expect(LeakReport::fromArray([])->suspectCount)->toBe(0);
    expect(LeakReport::fromArray([])->suspects)->toBe([]);
    expect(LeakReport::fromArray(['stats' => 'nonsense', 'suspects' => 'nope'])->uncoveredCount)->toBe(0);
    expect(LeakReport::fromArray(['suspects' => ['not-an-object', 42]])->suspects)->toBe([]);

    $suspect = LeakSuspect::fromArray([]);
    expect($suspect->safetyNetId)->toBe('')
        ->and($suspect->pipelineClass)->toBeNull()
        ->and($suspect->spanLen)->toBe(0)
        ->and($suspect->fieldPath)->toBeNull()
        ->and($suspect->score)->toBeNull();
});

it('reports Verified only when there are no suspects and full coverage', function () {
    $report = LeakReport::fromArray(leakReportArray());

    expect($report->coverageState())->toBe(CoverageState::Verified)
        ->and($report->hasSuspectedLeak())->toBeFalse();
});

it('reports Unverified when coverage is partial and no suspects are flagged', function (string $gapField) {
    $report = LeakReport::fromArray(leakReportArray([$gapField => 1]));

    expect($report->coverageState())->toBe(CoverageState::Unverified)
        ->and($report->hasSuspectedLeak())->toBeFalse();
})->with([
    'uncovered' => 'uncovered_count',
    'partial bleed' => 'partial_bleed_count',
    'class mismatch' => 'class_mismatch_count',
    'locale skipped' => 'locale_skipped_count',
]);

it('reports Suspect when the safety net actively flags a possible leak', function () {
    $report = LeakReport::fromArray(leakReportArray(['suspect_count' => 1]));

    expect($report->coverageState())->toBe(CoverageState::Suspect)
        ->and($report->hasSuspectedLeak())->toBeTrue();
});

it('prioritises Suspect over Unverified when both suspects and gaps are present', function () {
    $report = LeakReport::fromArray(leakReportArray([
        'suspect_count' => 1,
        'uncovered_count' => 3,
    ]));

    expect($report->coverageState())->toBe(CoverageState::Suspect);
});

it('exposes replay_hash when present and null otherwise', function () {
    expect(LeakReport::fromArray(leakReportArray())->replayHash)->toBeNull();
    expect(LeakReport::fromArray(leakReportArray([], [], 'a1b2c3'))->replayHash)->toBe('a1b2c3');
});

it('carries only safe metadata — never raw PII from a hostile leak_report', function () {
    // A defensive fixture: a future/compromised binary that smuggles raw PII and
    // byte offsets into a suspect element alongside the safe metadata fields.
    // The DTO must read ONLY its allowlist, so the secret values never survive.
    $secret = 'alice@secret.example';

    $report = LeakReport::fromArray(leakReportArray(
        ['suspect_count' => 1],
        [[
            'safety_net_id' => 'openai-privacy-filter-subprocess',
            'raw_label' => 'private_email',
            'mapped_class' => 'Email',
            'leak_kind' => 'uncovered',
            'span_len' => 20,
            // Hostile extras that must be dropped:
            'raw' => $secret,
            'text' => $secret,
            'value' => $secret,
            'source_text' => $secret,
            'span' => ['start' => 8, 'end' => 28],
            'start' => 8,
            'end' => 28,
        ]],
    ));

    $serialized = json_encode($report, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain($secret)
        ->and($serialized)->not->toContain('source_text')
        ->and($serialized)->not->toContain('"start"')
        ->and($serialized)->not->toContain('"end"')
        // ...while the safe metadata is retained:
        ->and($report->suspects[0]->rawLabel)->toBe('private_email')
        ->and($report->suspects[0]->mappedClass)->toBe('Email')
        ->and($report->suspects[0]->spanLen)->toBe(20);
});

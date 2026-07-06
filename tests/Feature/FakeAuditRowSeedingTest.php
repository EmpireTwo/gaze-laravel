<?php

declare(strict_types=1);

use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\Testing\FakeAuditService;

it('seeds audit query rows that flow back through the facade fake', function () {
    // Rows use the real builder's return shape: TSV positional lists
    // (list<list<string>>), one inner list per audit row.
    $rows = [
        ['2026-01-01T00:00:00Z', 'email', 'tokenize', 'sess-1'],
        ['2026-01-02T00:00:00Z', 'name', 'tokenize', 'sess-2'],
    ];

    Gaze::fake()->withAuditRows($rows);

    expect(Gaze::audit()->query()->execute())->toBe($rows);
});

it('returns seeded audit rows regardless of applied filters', function () {
    // Filters stay no-op recorders on the fake — scripted rows come back
    // either way, matching the documented FakeQueryBuilder behaviour.
    $rows = [['2026-01-01T00:00:00Z', 'email', 'tokenize', 'sess-1']];

    $fake = Gaze::fake()->withAuditRows($rows);

    $builder = $fake->audit()->query()->whereClass('email')->onlyRestoreEvents();

    expect($builder->execute())->toBe($rows)
        ->and($builder->appliedFilters())->toBe([
            '--class' => 'email',
            '--restore-events' => true,
        ]);
});

it('seeds rows into every query builder the fake hands out', function () {
    $rows = [['2026-01-01T00:00:00Z', 'email', 'tokenize', 'sess-1']];

    Gaze::fake()->withAuditRows($rows);

    expect(Gaze::audit()->query()->execute())->toBe($rows)
        ->and(Gaze::audit()->query()->execute())->toBe($rows);
});

it('seeds safety-net rows that flow back through the facade fake', function () {
    $rows = [
        ['2026-01-03T00:00:00Z', 'fresh', 'EMAIL', 'email', 'body.text'],
    ];

    Gaze::fake()->withSafetyNetRows($rows);

    $seeded = Gaze::audit()->safetyNetQuery()->whereLeakKind('fresh')->execute();

    expect($seeded)->toBe($rows)
        ->and(Gaze::getFacadeRoot()->audit()->safetyNetQueryCalls())->toBe([
            ['filters' => ['--leak-kind' => 'fresh']],
        ]);
});

it('defaults to empty result sets when nothing is seeded', function () {
    Gaze::fake();

    expect(Gaze::audit()->query()->execute())->toBe([])
        ->and(Gaze::audit()->safetyNetQuery()->execute())->toBe([]);
});

it('chains row seeding with the other fluent configuration', function () {
    $fake = Gaze::fake()
        ->withAuditRows([['row-a']])
        ->withSafetyNetRows([['row-b']]);

    expect(Gaze::audit()->query()->execute())->toBe([['row-a']])
        ->and(Gaze::audit()->safetyNetQuery()->execute())->toBe([['row-b']])
        ->and($fake)->toBe(Gaze::getFacadeRoot());
});

it('seeds rows directly on a standalone FakeAuditService too', function () {
    $audit = (new FakeAuditService)
        ->withQueryRows([['row-a']])
        ->withSafetyNetRows([['row-b']]);

    expect($audit->query()->execute())->toBe([['row-a']])
        ->and($audit->safetyNetQuery()->execute())->toBe([['row-b']]);
});

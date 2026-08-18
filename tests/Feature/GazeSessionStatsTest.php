<?php

declare(strict_types=1);

use CertaMesh\Gaze\LoadedDictionary;
use Illuminate\Support\Facades\Process;

/**
 * The upstream `stats` object carries more than a detection count:
 * `locale_chain` (what the binary actually resolved), `dictionaries_loaded`
 * (which term lists were in play) and `context_source`. These pin the
 * projection of every one of those fields onto GazeSession.
 *
 * @param  array<string, mixed>  $stats
 */
function cleanOutputWithStats(array $stats = []): string
{
    return json_encode([
        'clean_text' => 'Hello Name_1',
        'session_blob' => 'blob-bytes',
        'stats' => array_merge(['detections' => 1], $stats),
    ], JSON_THROW_ON_ERROR);
}

it('projects the resolved locale chain onto the session', function () {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithStats(['locale_chain' => ['de-DE', 'en']])),
    ]);

    $session = $this->makeGaze()->clean('Hallo Alice');

    expect($session->localeChain)->toBe(['de-DE', 'en'])
        ->and($session->activeLocale())->toBe('de-DE');
});

it('projects loaded dictionaries into typed rows', function () {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithStats([
            'dictionaries_loaded' => [
                ['name' => 'company-terms', 'term_count' => 42, 'source' => 'rulepack:core'],
                ['name' => 'staff', 'term_count' => 7, 'source' => 'policy'],
            ],
        ])),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->dictionariesLoaded)->toHaveCount(2)
        ->and($session->dictionariesLoaded[0])->toBeInstanceOf(LoadedDictionary::class)
        ->and($session->dictionariesLoaded[0]->name)->toBe('company-terms')
        ->and($session->dictionariesLoaded[0]->termCount)->toBe(42)
        ->and($session->dictionariesLoaded[0]->source)->toBe('rulepack:core')
        ->and($session->dictionariesLoaded[1]->termCount)->toBe(7);
});

it('projects context_source when the binary reports one', function () {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithStats(['context_source' => 'cli'])),
    ]);

    expect($this->makeGaze()->clean('Hello Alice')->contextSource)->toBe('cli');
});

it('degrades to empty stats projections when the binary omits the fields', function () {
    Process::fake([
        '*' => Process::result(output: cleanOutputWithStats()),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->localeChain)->toBe([])
        ->and($session->activeLocale())->toBeNull()
        ->and($session->dictionariesLoaded)->toBe([])
        ->and($session->contextSource)->toBeNull()
        ->and($session->detections)->toBe(1);
});

it('never fails a clean over a malformed stats shape', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob-bytes',
            'stats' => [
                'detections' => 'not-a-number',
                'locale_chain' => ['de-DE', 42, '', null],
                'dictionaries_loaded' => ['not-an-object', ['name' => 'partial']],
                'context_source' => 99,
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->detections)->toBe(0)
        ->and($session->localeChain)->toBe(['de-DE'])
        ->and($session->dictionariesLoaded)->toHaveCount(1)
        ->and($session->dictionariesLoaded[0]->name)->toBe('partial')
        ->and($session->dictionariesLoaded[0]->termCount)->toBe(0)
        ->and($session->dictionariesLoaded[0]->source)->toBe('')
        ->and($session->contextSource)->toBeNull();
});

it('keeps stats projections absent when the response carries no stats object', function () {
    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello Name_1',
            'session_blob' => 'blob-bytes',
        ], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->makeGaze()->clean('Hello Alice');

    expect($session->detections)->toBe(0)
        ->and($session->localeChain)->toBe([])
        ->and($session->dictionariesLoaded)->toBe([]);
});

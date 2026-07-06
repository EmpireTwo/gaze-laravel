<?php

declare(strict_types=1);

use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\Install\BinaryDownloader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $binary = getenv('GAZE_BINARY');
    if (! is_string($binary) || $binary === '') {
        $vendor = dirname(__DIR__, 2).'/vendor/bin/gaze';
        if (is_executable($vendor)) {
            $binary = $vendor;
        } else {
            $path = (new ExecutableFinder)->find('gaze');
            $binary = $path !== null ? $path : '';
        }
    }

    if ($binary === '') {
        $this->markTestSkipped('No gaze binary found (env GAZE_BINARY, vendor/bin/gaze, or PATH).');
    }

    // These tests pin the published policy's behavior against the pinned
    // binary generation, so gate on a version FLOOR instead of an exact-major
    // sniff: the old `str_contains($versionOutput, '0.5.')` gate went stale
    // when the pin moved to 0.11.x and silently perma-skipped the whole file.
    // Older binaries skip loudly; the pinned version and anything newer runs
    // (and fails loudly on an upstream behavior change — that is the point).
    $versionProcess = new Process([$binary, '--version']);
    $versionProcess->run();
    $versionOutput = trim($versionProcess->getOutput());
    $version = BinaryDownloader::parseVersion($versionOutput) ?? '';
    if ($version === '') {
        $this->fail("Gaze binary at {$binary} reported unparseable version output '{$versionOutput}'.");
    }
    if (version_compare($version, BinaryDownloader::PINNED_VERSION, '<')) {
        $this->markTestSkipped(
            "Gaze binary at {$binary} is v{$version}; behavior pins require the pinned v"
            .BinaryDownloader::PINNED_VERSION.' or newer (run `php artisan gaze:install --force`).'
        );
    }

    $this->app['config']->set('gaze.binary', $binary);
    $this->app['config']->set('gaze.policy_path', gl_integrationPolicyPath());
});

it('tokenizes 5000€ whole - no leading-digit drop', function () {
    $session = $this->app->make(Gaze::class)->clean('Order total 5000€ due today.');

    expect($session->cleanText)
        ->not->toContain('5000€')
        ->not->toMatch('/\b5\b/')
        ->not->toMatch('/000€/');
});

it('tokenizes 1.500,00 EUR (DE thousand-sep + decimal)', function () {
    $session = $this->app->make(Gaze::class)->clean('Betrag 1.500,00 EUR fällig.');

    expect($session->cleanText)->not->toContain('1.500,00 EUR');
});

it('does NOT tokenize currency-shaped substrings inside identifiers', function () {
    $session = $this->app->make(Gaze::class)->clean('Marker abc5000EURdef in log.');

    expect($session->cleanText)->toContain('abc5000EURdef');
});

it('does NOT tokenize bare amounts without a currency token', function () {
    $session = $this->app->make(Gaze::class)->clean('Counter is at 1,500.00 hits.');

    expect($session->cleanText)->toContain('1,500.00');
});

it('tokenizes adjacent amounts with no spacing collapse', function () {
    $session = $this->app->make(Gaze::class)->clean('Posten: 5000€ 1000€ MwSt');

    expect($session->cleanText)
        ->not->toContain('5000€')
        ->not->toContain('1000€');

    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $session->cleanText);
    expect($normalized)->toBe('Posten: <AMOUNT> <AMOUNT> MwSt');
});

it('tokenizes amount adjacent to currency-prefixed amount', function () {
    $session = $this->app->make(Gaze::class)->clean('Total $100 €200 GBP300 due.');

    expect($session->cleanText)
        ->not->toContain('$100')
        ->not->toContain('€200')
        ->not->toContain('GBP300');

    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $session->cleanText);
    expect($normalized)->toBe('Total <AMOUNT> <AMOUNT> <AMOUNT> due.');
});

it('tokenizes alpha-code currency amounts (EUR/USD/GBP) on either side of the digits', function () {
    // Alphabetic currency codes are word characters, so prefix (GBP300,
    // USD 2,500.00) and suffix (1500 EUR, 99.95 usd) forms tokenize whole
    // under plain \b guards. Guards against losing this half while touching
    // the symbol-side boundary groups (see #141 history).
    $gaze = $this->app->make(Gaze::class);

    $prefix = $gaze->clean('Fee GBP300 and USD 2,500.00 charged.');
    expect($prefix->cleanText)
        ->not->toContain('GBP300')
        ->not->toContain('USD 2,500.00');
    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $prefix->cleanText);
    expect($normalized)->toBe('Fee <AMOUNT> and <AMOUNT> charged.');

    $suffix = $gaze->clean('Betrag 1500 EUR offen, refund of 99.95 usd sent.');
    expect($suffix->cleanText)
        ->not->toContain('1500 EUR')
        ->not->toContain('99.95 usd');
    $normalized = preg_replace('/<[^>]+:Custom:amount[^>]*>/', '<AMOUNT>', $suffix->cleanText);
    expect($normalized)->toBe('Betrag <AMOUNT> offen, refund of <AMOUNT> sent.');
});

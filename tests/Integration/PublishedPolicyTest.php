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

it('redacts the German fixture across email + invoice + amount + org + location + iban + phone + postal', function () {
    $de = 'Sehr geehrte Damen und Herren, anbei Rechnung Nr. RE-2026-0042 über 1.500,00 EUR '.
          'von der Acme GmbH, Musterstraße 12, 10115 Berlin. IBAN DE89370400440532013000, '.
          'Telefon +49 30 12345678, Mail kontakt@acme.example.';

    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean($de);

    // Raw-leak negative: clean text must not contain any sensitive substring.
    // The #141 IBAN leak (collision-family class) is closed by the policy's
    // family rule and asserted here again alongside its dedicated case below.
    expect($session->cleanText)
        ->not->toContain('RE-2026-0042')
        ->not->toContain('DE89370400440532013000')
        ->not->toContain('1.500,00 EUR')
        ->not->toContain('Acme GmbH')
        ->not->toContain('Musterstraße 12')
        ->not->toContain('+49 30 12345678')
        ->not->toContain('kontakt@acme.example')
        ->not->toContain('10115');

    // Class-family positive: clean text must contain at least four token families.
    $families = collect([
        'Email',
        'Custom:reference_number',
        'Custom:amount',
        'Organization',
        'Location',
        'Custom:postal_code',
        'Custom:iban',
        'Custom:phone',
    ])->filter(fn (string $family) => str_contains($session->cleanText, "<{$family}") || str_contains($session->cleanText, ":{$family}"));

    expect($families->count())->toBeGreaterThanOrEqual(4);

    $restored = $gaze->restore($session, $session->cleanText);
    expect($restored)->toBe($de);
});

it('redacts the English fixture across email + invoice + amount + org + location + iban + phone', function () {
    $en = 'Hi team - invoice INV-2026-09812 for $3,500.00 from Acme Inc. at 10 Downing Street. '.
          'IBAN GB29NWBK60161331926819, contact +1 212 555 0100. Mail support@acme.example.';

    $gaze = $this->app->make(Gaze::class);
    $session = $gaze->clean($en);

    // The two #141 leaks ('$3,500.00' symbol-amount, 'GB29NWBK60161331926819'
    // collision-family IBAN) are closed by the policy fixes and asserted here
    // again alongside their dedicated cases.
    expect($session->cleanText)
        ->not->toContain('INV-2026-09812')
        ->not->toContain('$3,500.00')
        ->not->toContain('GB29NWBK60161331926819')
        ->not->toContain('Acme Inc.')
        ->not->toContain('10 Downing Street')
        ->not->toContain('+1 212 555 0100')
        ->not->toContain('support@acme.example');

    $families = collect([
        'Email',
        'Custom:reference_number',
        'Custom:amount',
        'Organization',
        'Location',
        'Custom:iban',
        'Custom:phone',
    ])->filter(fn (string $family) => str_contains($session->cleanText, "<{$family}") || str_contains($session->cleanText, ":{$family}"));

    expect($families->count())->toBeGreaterThanOrEqual(4);

    $restored = $gaze->restore($session, $session->cleanText);
    expect($restored)->toBe($en);
});

it('redacts IBANs via the core rulepack', function () {
    $session = $this->app->make(Gaze::class)->clean('IBAN DE89370400440532013000 on file, backup GB29NWBK60161331926819.');

    expect($session->cleanText)
        ->not->toContain('DE89370400440532013000')
        ->not->toContain('GB29NWBK60161331926819');
});

it('redacts symbol-currency amounts ($/€/£) via the published policy', function () {
    $session = $this->app->make(Gaze::class)->clean('Paid $3,500.00 up front and 5000€ on delivery.');

    expect($session->cleanText)
        ->not->toContain('$3,500.00')
        ->not->toContain('5000€');
});

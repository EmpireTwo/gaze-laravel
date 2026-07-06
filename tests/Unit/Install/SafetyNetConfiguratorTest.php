<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\SafetyNetConfigStatus;
use CertaMesh\Gaze\Install\SafetyNetConfigurator;

function snc_tempEnv(string $contents = "APP_ENV=testing\n"): string
{
    $path = sys_get_temp_dir().'/gaze-env-'.bin2hex(random_bytes(6));
    file_put_contents($path, $contents);

    return $path;
}

it('builds opf env pairs, wiring the local subprocess command + checkpoint when given', function () {
    expect(SafetyNetConfigurator::pairsFor('opf', null))
        ->toBe([
            'GAZE_SAFETY_NET' => 'true',
            'GAZE_SAFETY_NET_BACKEND' => 'openai-filter',
        ]);

    expect(SafetyNetConfigurator::pairsFor('opf', null, '/usr/local/bin/opf', '/models/opf-checkpoint'))
        ->toBe([
            'GAZE_SAFETY_NET' => 'true',
            'GAZE_SAFETY_NET_BACKEND' => 'openai-filter',
            'GAZE_OPENAI_FILTER_COMMAND' => '/usr/local/bin/opf',
            'GAZE_OPENAI_FILTER_CHECKPOINT' => '/models/opf-checkpoint',
        ]);
});

it('builds kiji env pairs with the ort/int8 defaults and model dir', function () {
    expect(SafetyNetConfigurator::pairsFor('kiji', '/models/kiji'))
        ->toBe([
            'GAZE_SAFETY_NET' => 'true',
            'GAZE_SAFETY_NET_BACKEND' => 'kiji-distilbert',
            'GAZE_KIJI_BACKEND' => 'ort',
            'GAZE_KIJI_DISTILBERT_PRECISION' => 'int8',
            'GAZE_KIJI_DISTILBERT_MODEL_DIR' => '/models/kiji',
        ]);

    expect(SafetyNetConfigurator::pairsFor('kiji', null))
        ->toBe([
            'GAZE_SAFETY_NET' => 'true',
            'GAZE_SAFETY_NET_BACKEND' => 'kiji-distilbert',
            'GAZE_KIJI_BACKEND' => 'ort',
            'GAZE_KIJI_DISTILBERT_PRECISION' => 'int8',
        ]);
});

it('upserts keys idempotently, preserving unrelated keys', function () {
    $env = snc_tempEnv("APP_ENV=testing\nGAZE_SAFETY_NET=false\n");
    $configurator = new SafetyNetConfigurator($env);

    try {
        $first = $configurator->apply(SafetyNetConfigurator::pairsFor('opf', null), force: false);
        expect($first->status)->toBe(SafetyNetConfigStatus::Written);
        expect(file_get_contents($env))
            ->toContain('GAZE_SAFETY_NET=true')
            ->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter')
            ->toContain('APP_ENV=testing');

        $second = $configurator->apply(SafetyNetConfigurator::pairsFor('opf', null), force: false);
        expect($second->status)->toBe(SafetyNetConfigStatus::Unchanged);
    } finally {
        @unlink($env);
        @unlink($env.'.backup');
    }
});

it('backs up the original .env exactly once to a 0600 .env.backup (CB6)', function () {
    $env = snc_tempEnv("APP_ENV=testing\nGAZE_SAFETY_NET=false\n");
    $original = file_get_contents($env);
    $configurator = new SafetyNetConfigurator($env);

    try {
        $configurator->apply(SafetyNetConfigurator::pairsFor('opf', null), force: false);

        $backup = $env.'.backup';
        expect(is_file($backup))->toBeTrue();
        expect(file_get_contents($backup))->toBe($original);
        expect(substr(sprintf('%o', fileperms($backup)), -4))->toBe('0600');

        // A --force re-run with different keys must NOT refresh the pristine backup.
        $configurator->apply(SafetyNetConfigurator::pairsFor('kiji', '/models/kiji'), force: true);
        expect(file_get_contents($backup))->toBe($original);
    } finally {
        @unlink($env);
        @unlink($env.'.backup');
    }
});

it('preview returns the env lines without mutating .env', function () {
    $env = snc_tempEnv();
    $before = file_get_contents($env);

    try {
        $out = (new SafetyNetConfigurator($env))->preview(SafetyNetConfigurator::pairsFor('opf', null));

        expect($out)->toContain('GAZE_SAFETY_NET=true');
        expect($out)->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');
        expect(file_get_contents($env))->toBe($before);
    } finally {
        @unlink($env);
    }
});

it('redacts sensitive values in preview so secrets never reach CI logs (sensitive-value seam)', function () {
    $env = snc_tempEnv();

    try {
        $out = (new SafetyNetConfigurator($env))->preview([
            'GAZE_SAFETY_NET' => 'true',
            'GAZE_OPENAI_API_KEY' => 'sk-super-secret-value',
        ]);

        expect($out)->toContain('GAZE_SAFETY_NET=true');
        expect($out)->not->toContain('sk-super-secret-value');
        expect(SafetyNetConfigurator::isSensitiveKey('GAZE_OPENAI_API_KEY'))->toBeTrue();
        expect(SafetyNetConfigurator::isSensitiveKey('GAZE_SAFETY_NET_BACKEND'))->toBeFalse();
    } finally {
        @unlink($env);
    }
});

it('creates .env when missing before upserting', function () {
    $env = sys_get_temp_dir().'/gaze-env-missing-'.bin2hex(random_bytes(6));

    try {
        $result = (new SafetyNetConfigurator($env))->apply(SafetyNetConfigurator::pairsFor('opf', null), force: false);

        expect($result->status)->toBe(SafetyNetConfigStatus::Written);
        expect(is_file($env))->toBeTrue();
        expect(file_get_contents($env))->toContain('GAZE_SAFETY_NET_BACKEND=openai-filter');
    } finally {
        @unlink($env);
        @unlink($env.'.backup');
    }
});

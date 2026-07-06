<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('publishes policy.toml via the gaze-policy tag', function () {
    $target = base_path('policy.toml');
    @unlink($target);

    try {
        Artisan::call('vendor:publish', [
            '--tag' => 'gaze-policy',
            '--force' => true,
        ]);

        expect($target)->toBeFile();
    } finally {
        @unlink($target);
    }
});

it('publishes config to application config path', function () {
    $target = $this->app->configPath('gaze.php');
    @unlink($target);

    Artisan::call('vendor:publish', [
        '--tag' => 'gaze-config',
        '--force' => true,
    ]);

    expect($target)->toBeFile();

    $published = require $target;
    expect($published)->toBeArray()
        ->toHaveKeys(['binary', 'timeout_seconds', 'policy_path', 'blob_encryption_key', 'audit_db_path']);

    // v0.13+ ships the safety-net family as a nested group (raw env reads,
    // no casts — GazeOptions::fromConfig is the coercion layer). The
    // deprecated flat root keys must NOT ship anymore.
    expect($published)->toHaveKey('safety_net');
    expect($published['safety_net'])->toBeArray()
        ->toHaveKeys([
            'enabled',
            'backend',
            'device',
            'timeout_ms',
            'input_limit_bytes',
            'mode',
            'fallback',
            'openai_filter',
            'kiji',
        ]);
    expect($published['safety_net']['openai_filter'])->toBeArray()
        ->toHaveKeys(['command', 'checkpoint', 'operating_point']);
    expect($published['safety_net']['kiji'])->toBeArray()
        ->toHaveKeys(['backend', 'distilbert_precision', 'distilbert_command', 'distilbert_model_dir']);
    foreach ([
        'safety_net_backend',
        'safety_net_device',
        'safety_net_timeout_ms',
        'safety_net_input_limit_bytes',
        'safety_net_mode',
        'safety_net_fallback',
        'openai_filter_command',
        'openai_filter_checkpoint',
        'openai_filter_operating_point',
        'kiji_backend',
        'kiji_distilbert_precision',
        'kiji_distilbert_command',
        'kiji_distilbert_model_dir',
    ] as $deprecatedFlatKey) {
        expect($published)->not->toHaveKey($deprecatedFlatKey);
    }

    expect($published)->toHaveKey('proxy');
    expect($published['proxy'])->toBeArray()
        ->toHaveKeys(['bind', 'session_ttl', 'rulepack', 'policy_path', 'upstream', 'stop_timeout']);
    expect($published['proxy']['upstream'])->toBeArray()
        ->toHaveKeys(['openai', 'anthropic', 'gemini']);

    expect($published)->toHaveKey('daemon');
    expect($published['daemon'])->toBeArray()
        ->toHaveKeys([
            'policy_path',
            'audit_db_path',
            'request_timeout_ms',
            'idle_timeout_s',
            'binary_path',
            'stderr_path',
        ]);
    expect($published['daemon']['policy_path'])->toBeNull();
    expect($published['daemon']['audit_db_path'])->toBeNull();
    expect($published['daemon']['idle_timeout_s'])->toBeNull();
    expect($published['daemon']['binary_path'])->toBeNull();
    expect($published['daemon']['stderr_path'])->toBeNull();
    expect((int) $published['daemon']['request_timeout_ms'])->toBe(5000);

    @unlink($target);
});

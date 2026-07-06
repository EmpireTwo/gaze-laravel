<?php

declare(strict_types=1);

use CertaMesh\Gaze\Gaze;
use CertaMesh\Gaze\GazeServiceProvider;
use Illuminate\Support\Facades\Process;

it('Gaze resolved from container forwards OpenAI privacy-filter config on clean argv', function () {
    config([
        'gaze.binary' => '/fake/gaze',
        'gaze.openai_filter_command' => '/usr/local/bin/opf',
        'gaze.openai_filter_checkpoint' => '/models/openai-filter',
        'gaze.openai_filter_operating_point' => 'high-precision',
        'gaze.safety_net_timeout_ms' => 7500,
        'gaze.safety_net_input_limit_bytes' => 123456,
        'gaze.safety_net_mode' => 'tolerant',
        'gaze.kiji_backend' => 'ort',
        'gaze.kiji_distilbert_precision' => 'int8',
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->app->make(Gaze::class)->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--openai-filter-command=/usr/local/bin/opf')
            ->toContain('--openai-filter-checkpoint=/models/openai-filter')
            ->toContain('--openai-filter-operating-point=high-precision')
            ->toContain('--safety-net-timeout-ms=7500')
            ->toContain('--safety-net-input-limit-bytes=123456')
            ->toContain('--safety-net-mode=tolerant')
            ->toContain('--kiji-backend=ort')
            ->toContain('--kiji-distilbert-precision=int8');

        return true;
    });
});

it('defaults gaze.restore_telemetry to null (telemetry off) when env is unset', function () {
    expect(config('gaze.restore_telemetry'))->toBeNull();
});

it('container-resolved Gaze forwards --telemetry and --audit-db when restore_telemetry config is enabled', function () {
    config([
        'gaze.binary' => '/fake/gaze',
        'gaze.restore_telemetry' => true,
        'gaze.audit_db_path' => '/var/lib/gaze/audit.sqlite',
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake([
        '*' => Process::result(output: json_encode(['text' => 'Email_1'], JSON_THROW_ON_ERROR)),
    ]);

    $session = $this->bindAndReturnCleanSession('Email_1', 'blob', 1);
    $this->app->make(Gaze::class)->restore($session, 'Email_1');

    Process::assertRan(function ($process): bool {
        $command = is_array($process->command) ? $process->command : [];
        if (! in_array('restore', $command, true)) {
            return false;
        }

        expect($command)
            ->toContain('--telemetry')
            ->toContain('--audit-db=/var/lib/gaze/audit.sqlite');

        return true;
    });
});

it('collapses the shipped nested safety_net group to the bool enable switch at boot', function () {
    // Legacy readers (gaze:daemon:serve, gaze:doctor, adopter code) treat
    // gaze.safety_net as a bool. The provider's normalization must have
    // already collapsed the nested group when no env enables it.
    expect(config('gaze.safety_net'))->toBeFalse();
});

it('back-fills deprecated flat keys from the nested safety_net group for legacy readers', function () {
    config()->set('gaze.safety_net', [
        'enabled' => true,
        'mode' => 'strict',
        'timeout_ms' => '2500',
        'openai_filter' => ['command' => '/usr/local/bin/opf'],
        'kiji' => ['backend' => 'ort'],
    ]);

    (new GazeServiceProvider($this->app))->register();

    expect(config('gaze.safety_net'))->toBeTrue()
        ->and(config('gaze.safety_net_mode'))->toBe('strict')
        ->and(config('gaze.safety_net_timeout_ms'))->toBe('2500')
        ->and(config('gaze.openai_filter_command'))->toBe('/usr/local/bin/opf')
        ->and(config('gaze.kiji_backend'))->toBe('ort');
});

it('does not let nested back-fill clobber an explicitly set flat key', function () {
    config()->set('gaze.safety_net', ['enabled' => false, 'mode' => 'strict']);
    config()->set('gaze.safety_net_mode', 'tolerant');

    (new GazeServiceProvider($this->app))->register();

    expect(config('gaze.safety_net_mode'))->toBe('tolerant');
});

it('leaves a pre-v0.13 flat bool safety_net config untouched', function () {
    config()->set('gaze.safety_net', true);
    config()->set('gaze.safety_net_mode', 'tolerant');

    (new GazeServiceProvider($this->app))->register();

    expect(config('gaze.safety_net'))->toBeTrue()
        ->and(config('gaze.safety_net_mode'))->toBe('tolerant');
});

it('Gaze resolved from container forwards the nested safety_net group on clean argv', function () {
    config([
        'gaze.binary' => '/fake/gaze',
        'gaze.safety_net' => [
            'enabled' => true,
            'backend' => 'kiji-distilbert',
            'mode' => 'strict',
            'timeout_ms' => '2500',
            'openai_filter' => ['command' => '/usr/local/bin/opf'],
            'kiji' => ['backend' => 'ort', 'distilbert_precision' => 'int8'],
        ],
    ]);
    $this->app->forgetInstance(Gaze::class);

    Process::fake([
        '*' => Process::result(output: json_encode([
            'clean_text' => 'Hello',
            'session_blob' => base64_encode('blob'),
            'stats' => ['detections' => 0],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $this->app->make(Gaze::class)->clean('Hello');

    Process::assertRan(function ($process): bool {
        expect($process->command)
            ->toContain('--safety-net=openai-filter')
            ->toContain('--safety-net-backend=kiji-distilbert')
            ->toContain('--safety-net-mode=strict')
            ->toContain('--safety-net-timeout-ms=2500')
            ->toContain('--openai-filter-command=/usr/local/bin/opf')
            ->toContain('--kiji-backend=ort')
            ->toContain('--kiji-distilbert-precision=int8');

        return true;
    });
});

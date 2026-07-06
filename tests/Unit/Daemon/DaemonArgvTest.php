<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\DaemonArgv;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * @param  array<string, mixed>  $daemon
 * @param  array<string, mixed>  $topLevel  Top-level `gaze.*` keys (one-shot pipeline config).
 */
function configRepoForArgv(array $daemon = [], array $topLevel = []): ConfigRepository
{
    return new ConfigRepository(['gaze' => array_merge($topLevel, ['daemon' => $daemon])]);
}

it('returns an empty flag list when nothing is configured', function () {
    expect(DaemonArgv::flags(configRepoForArgv()))->toBe([]);
});

it('omits flags whose config value is null so upstream defaults apply', function () {
    $config = configRepoForArgv(daemon: [
        'policy_path' => '/etc/gaze/policy.toml',
        'idle_timeout_s' => null,
        'session_idle_timeout_s' => null,
        'session_cap' => null,
        'audit_db_path' => null,
        'ner_model_dir' => null,
        'ner_locale' => null,
        'kiji_distilbert_locales' => null,
    ]);

    expect(DaemonArgv::flags($config))->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('assembles the full daemon flag surface from config in pinned order', function () {
    $config = configRepoForArgv(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'idle_timeout_s' => 1800,
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
            'audit_db_path' => '/var/lib/gaze/audit.sqlite',
            'ner_model_dir' => '/opt/gaze/ner-model',
            'ner_locale' => 'de',
            'kiji_distilbert_locales' => 'de,fr',
        ],
        topLevel: [
            'safety_net' => true,
            'safety_net_backend' => 'kiji-distilbert',
            'locale' => 'de,en',
            'ner_threshold' => 0.75,
            'safety_net_device' => 'cpu',
            'openai_filter_command' => '/usr/local/bin/opf',
            'openai_filter_checkpoint' => '/opt/opf/checkpoint',
            'openai_filter_operating_point' => 'high-recall',
            'kiji_backend' => 'ort',
            'kiji_distilbert_command' => '/usr/local/bin/kiji',
            'kiji_distilbert_model_dir' => '/opt/kiji/model',
            'safety_net_timeout_ms' => 7500,
            'safety_net_input_limit_bytes' => 2097152,
            'safety_net_mode' => 'strict',
            'safety_net_fallback' => 'redact',
        ],
    );

    expect(DaemonArgv::flags($config))->toBe([
        '--policy=/etc/gaze/policy.toml',
        '--safety-net=openai-filter',
        '--safety-net-backend=kiji-distilbert',
        '--idle-timeout=1800',
        '--session-idle-timeout=3600',
        '--session-cap=500',
        '--audit-db=/var/lib/gaze/audit.sqlite',
        '--locale=de,en',
        '--ner-threshold=0.75',
        '--ner-model-dir=/opt/gaze/ner-model',
        '--ner-locale=de',
        '--openai-filter-device=cpu',
        '--openai-filter-command=/usr/local/bin/opf',
        '--openai-filter-checkpoint=/opt/opf/checkpoint',
        '--openai-filter-operating-point=high-recall',
        '--kiji-backend=ort',
        '--kiji-distilbert-command=/usr/local/bin/kiji',
        '--kiji-distilbert-model-dir=/opt/kiji/model',
        '--kiji-distilbert-locales=de,fr',
        '--safety-net-timeout-ms=7500',
        '--safety-net-input-limit-bytes=2097152',
        '--safety-net-mode=strict',
        '--safety-net-fallback=redact',
    ]);
});

it('omits --safety-net when gaze.safety_net is false', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '/etc/gaze/policy.toml'],
        topLevel: ['safety_net' => false],
    );

    expect(DaemonArgv::flags($config))->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('lets caller overrides win over config for the operational knobs', function () {
    $config = configRepoForArgv(
        daemon: [
            'policy_path' => '/etc/gaze/policy.toml',
            'idle_timeout_s' => 1800,
            'session_idle_timeout_s' => 3600,
            'session_cap' => 500,
            'audit_db_path' => '/var/lib/gaze/audit.sqlite',
        ],
        topLevel: [
            'locale' => 'de',
            'ner_threshold' => 0.5,
        ],
    );

    $argv = DaemonArgv::flags($config, [
        'policy' => '/tmp/override.toml',
        'idle-timeout' => '60',
        'session-idle-timeout' => '120',
        'session-cap' => '25',
        'audit-db' => '/tmp/audit.sqlite',
        'locale' => 'en',
        'ner-threshold' => '0.9',
    ]);

    expect($argv)->toBe([
        '--policy=/tmp/override.toml',
        '--idle-timeout=60',
        '--session-idle-timeout=120',
        '--session-cap=25',
        '--audit-db=/tmp/audit.sqlite',
        '--locale=en',
        '--ner-threshold=0.9',
    ]);
});

it('treats empty-string config values as unset', function () {
    $config = configRepoForArgv(
        daemon: ['policy_path' => '', 'audit_db_path' => ''],
        topLevel: ['locale' => ''],
    );

    expect(DaemonArgv::flags($config))->toBe([]);
});

it('keeps a zero ner-threshold (numeric, not truthy) in the flag list', function () {
    $config = configRepoForArgv(topLevel: ['ner_threshold' => 0]);

    expect(DaemonArgv::flags($config))->toBe(['--ner-threshold=0']);
});

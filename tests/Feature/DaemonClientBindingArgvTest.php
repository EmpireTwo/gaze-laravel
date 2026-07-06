<?php

declare(strict_types=1);

use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;
use CertaMesh\Gaze\Daemon\DaemonArgv;
use CertaMesh\Gaze\Daemon\DaemonClient;
use CertaMesh\Gaze\Exceptions\GazeDaemonFeatureUnsupportedException;

/**
 * Pins the provider's scoped DaemonClient binding — the `Gaze::daemon()`
 * spawn path — to the shared DaemonArgv assembler, so the facade path
 * carries the FULL daemon flag surface (not just the legacy
 * policy / idle-timeout / audit-db trio).
 *
 * The spawn itself is not exercised (no binary); the argv tail is read
 * off the constructed client via reflection, the same seam
 * DaemonClient::connect() consumes.
 */

/**
 * @return list<string>
 */
function spawnFlagsOf(DaemonClientContract $client): array
{
    expect($client)->toBeInstanceOf(DaemonClient::class);

    $property = new ReflectionProperty(DaemonClient::class, 'flags');

    /** @var list<string> */
    return $property->getValue($client);
}

beforeEach(function () {
    config()->set('gaze.daemon.policy_path', '/etc/gaze/policy.toml');
    config()->set('gaze.daemon.binary_path', '/fake/gaze');
});

it('spawns the facade daemon client with config-set NER and safety-net flags', function () {
    config()->set('gaze.daemon.session_idle_timeout_s', 900);
    config()->set('gaze.daemon.session_cap', 500);
    config()->set('gaze.daemon.ner_model_dir', '/opt/gaze/ner-model');
    config()->set('gaze.daemon.kiji_distilbert_locales', 'de,fr');
    config()->set('gaze.locale', 'de,en');
    config()->set('gaze.ner_threshold', 0.75);
    config()->set('gaze.safety_net_backend', 'kiji-distilbert');
    config()->set('gaze.safety_net_mode', 'strict');

    $flags = spawnFlagsOf($this->app->make(DaemonClientContract::class));

    expect($flags)
        ->toContain('--policy=/etc/gaze/policy.toml')
        ->toContain('--session-idle-timeout=900')
        ->toContain('--session-cap=500')
        ->toContain('--ner-model-dir=/opt/gaze/ner-model')
        ->toContain('--kiji-distilbert-locales=de,fr')
        ->toContain('--locale=de,en')
        ->toContain('--ner-threshold=0.75')
        ->toContain('--safety-net-backend=kiji-distilbert')
        ->toContain('--safety-net-mode=strict');
});

it('spawns the facade daemon client with exactly the shared DaemonArgv assembly', function () {
    config()->set('gaze.daemon.idle_timeout_s', 1800);
    config()->set('gaze.daemon.audit_db_path', '/var/lib/gaze/audit.sqlite');
    config()->set('gaze.safety_net', true);

    $flags = spawnFlagsOf($this->app->make(DaemonClientContract::class));

    expect($flags)->toBe(DaemonArgv::flags($this->app->make('config')));
});

it('omits unset daemon flags on the facade spawn path so upstream defaults apply', function () {
    $flags = spawnFlagsOf($this->app->make(DaemonClientContract::class));

    expect($flags)->toBe(['--policy=/etc/gaze/policy.toml']);
});

it('still refuses to build the facade daemon client without a policy path', function () {
    config()->set('gaze.daemon.policy_path', null);

    $this->app->make(DaemonClientContract::class);
})->throws(GazeDaemonFeatureUnsupportedException::class, 'gaze.daemon.policy_path');

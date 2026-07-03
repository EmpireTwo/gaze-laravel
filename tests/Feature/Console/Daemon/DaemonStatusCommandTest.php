<?php

declare(strict_types=1);

use CertaMesh\Gaze\Console\Daemon\DaemonStatusCommand;
use Illuminate\Support\Facades\Process;

it('exits 0 and lists PIDs when pgrep returns matches', function () {
    Process::fake([
        '*' => Process::result(output: "1234 /usr/local/bin/gaze daemon --policy=/etc/gaze/policy.toml\n5678 /opt/gaze daemon\n", exitCode: 0),
    ]);

    $this->artisan('gaze:daemon:status')->assertExitCode(0);
});

it('exits 1 when pgrep returns no matches', function () {
    Process::fake(['*' => Process::result(output: '', exitCode: 1)]);

    $this->artisan('gaze:daemon:status')
        ->expectsOutputToContain('no processes found')
        ->assertExitCode(1);
});

it('shells out to the platform-appropriate pgrep', function () {
    Process::fake(['*' => Process::result(output: '1 gaze daemon', exitCode: 0)]);

    $this->artisan('gaze:daemon:status')->assertExitCode(0);

    Process::assertRan(function ($process): bool {
        expect($process->command)->toBe(DaemonStatusCommand::discoveryCommand(PHP_OS_FAMILY));

        return true;
    });
});

it('uses the BSD pgrep spelling on darwin and procps on everything else', function () {
    expect(DaemonStatusCommand::discoveryCommand('Darwin'))->toBe(['pgrep', '-fl', 'gaze daemon'])
        ->and(DaemonStatusCommand::discoveryCommand('Linux'))->toBe(['pgrep', '-af', 'gaze daemon']);
});

it('never reports its own PID as a discovered daemon', function () {
    $own = (string) getmypid();
    Process::fake([
        '*' => Process::result(output: "{$own} php artisan gaze:daemon:status\n", exitCode: 0),
    ]);

    $this->artisan('gaze:daemon:status')
        ->expectsOutputToContain('no processes found')
        ->assertExitCode(1);
});

it('still lists other daemons when its own PID is among the matches', function () {
    $own = (string) getmypid();
    Process::fake([
        '*' => Process::result(output: "{$own} php artisan gaze:daemon:status\n4242 /usr/local/bin/gaze daemon\n", exitCode: 0),
    ]);

    $this->artisan('gaze:daemon:status')
        ->expectsOutputToContain('4242')
        ->doesntExpectOutputToContain('gaze:daemon:status')
        ->assertExitCode(0);
});

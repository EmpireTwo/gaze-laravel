<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Testing;

use CertaMesh\Gaze\Contracts\DaemonManager as DaemonManagerContract;
use CertaMesh\Gaze\Daemon\CleanResponse;
use CertaMesh\Gaze\Daemon\Contracts\DaemonClientContract;
use CertaMesh\Gaze\Exceptions\GazeException;

/**
 * Test double for the `Gaze::daemon()` chain. Records every call and
 * returns a deterministic `CleanResponse` so assertions can run without
 * a real `gaze daemon` subprocess.
 *
 * Implements `Contracts\DaemonManager` directly (no longer extends the
 * concrete `DaemonManager`), so it never carries a real client.
 *
 * Adopter usage:
 *
 *     Gaze::fake();
 *     Gaze::daemon()->session('agent-a')->clean('hi');
 *     Gaze::assertDaemonCleaned('agent-a');
 */
final class FakeDaemonManager implements DaemonManagerContract
{
    /** @var list<array{session_id: string, text: string}> */
    private array $calls = [];

    /** @var array<string, FakeDaemonSession> */
    private array $sessionDoubles = [];

    private ?GazeException $failure = null;

    public function __construct(
        private ?\Closure $cleanHandler = null,
    ) {}

    /**
     * Script the response of every `clean()` call. Usually configured via
     * `Gaze::fake()->daemonCleanUsing()`.
     *
     * @param  \Closure(string, string): CleanResponse  $handler
     */
    public function cleanUsing(\Closure $handler): static
    {
        $this->cleanHandler = $handler;

        return $this;
    }

    /**
     * Make every `clean()` call throw after recording it. Usually
     * configured via `Gaze::fake()->failWith()`, which fans the failure
     * out to the one-shot verbs and this daemon path together.
     */
    public function failWith(GazeException $exception): static
    {
        $this->failure = $exception;

        return $this;
    }

    public function session(string $id): FakeDaemonSession
    {
        if (! isset($this->sessionDoubles[$id])) {
            $this->sessionDoubles[$id] = new FakeDaemonSession($id, $this);
        }

        return $this->sessionDoubles[$id];
    }

    public function clean(string $sessionId, string $text): CleanResponse
    {
        $this->calls[] = ['session_id' => $sessionId, 'text' => $text];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->cleanHandler !== null) {
            return ($this->cleanHandler)($sessionId, $text);
        }

        // Same shared tokenizer as FakeGaze::clean(), so the daemon path
        // masks identically to the one-shot path under Gaze::fake().
        $cleanText = FakeTokenizer::mask($text);

        return new CleanResponse(
            sessionId: $sessionId,
            cleanText: $cleanText,
            manifest: [],
            tokens: [],
            raw: [
                'session_id' => $sessionId,
                'clean_text' => $cleanText,
                'manifest' => [],
                'tokens' => [],
            ],
        );
    }

    /**
     * The fake never spawns a daemon, so there is no client to expose.
     * Fails loudly instead of pretending a transport exists.
     */
    public function client(): DaemonClientContract
    {
        throw new \LogicException(
            'FakeDaemonManager holds no real daemon client. Bind a DaemonClientContract test double directly if your test needs client-level access.'
        );
    }

    /**
     * @return list<array{session_id: string, text: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}

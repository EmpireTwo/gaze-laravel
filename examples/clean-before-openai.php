<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Clean before OpenAI — the trust contract (clean → draft → restore → send)
|--------------------------------------------------------------------------
|
| Gaze pseudonymizes PII/PHI/secrets BEFORE any text crosses the model
| boundary. The contract this example demonstrates:
|
|   • Only $session->cleanText is ever sent to the LLM. It carries
|     pseudonymous tokens — real gaze tokens look like <ea0200d1:Email_1>
|     (a per-session hex prefix + class + counter), never the literal value.
|   • $session->ciphertext (an encrypted blob) and $session->entries (the
|     token <-> real-value map) NEVER leave your server. Keep them server-side.
|   • Restore is owner-side: only code holding the session can turn the
|     model's tokenized reply back into real values — and only THEN can you
|     act on them (send the email, confirm the SSN). The model never could:
|     it only ever saw tokens, so it can neither leak nor act on the PII.
|   • clean() also grades ITSELF. $session->coverageState() carries upstream's
|     own coverage verdict (Verified / Unverified / Suspect), and
|     $session->hasSuspectedLeak() is the hard red: a span may still carry raw
|     PII in cleanText. Check it BEFORE the text crosses the boundary — a
|     detection count is not a verification.
|
| That last point is the whole value proposition: reversibility. The model
| drafts in tokens; restore() + the owner-side session re-enable the real,
| owner-side action. This file shows that end-to-end with a stubbed send.
|
| Detection logic lives upstream in the `gaze` binary; this package only
| exposes it through Laravel surfaces. A real round-trip needs the binary,
| so these snippets run inside a booted Laravel app (tinker / route / job),
| not as bare `php file.php` scripts — see examples/README.md.
*/

use CertaMesh\Gaze\CoverageState;
use CertaMesh\Gaze\Entry;
use CertaMesh\Gaze\Facades\Gaze;
use CertaMesh\Gaze\GazeSession;

/**
 * Find the first detected entry whose PII class matches $needle (case-
 * insensitive substring). Class strings come from upstream and vary with the
 * active policy — built-in email is "Email", a name is "Name", a custom SSN
 * recognizer might be "custom:us_ssn" — so we match loosely instead of pinning
 * one exact string. Returns null when that class wasn't detected.
 */
function entryForClass(GazeSession $session, string $needle): ?Entry
{
    foreach ($session->entries as $entry) {
        if (str_contains(strtolower($entry->class), strtolower($needle))) {
            return $entry;
        }
    }

    return null;
}

/**
 * Stand-in for a real provider call (OpenAI, Anthropic, …). A real model only
 * ever receives $session->cleanText. We pass the whole $session here for ONE
 * reason: so the stub can fish out the exact tokens gaze minted and echo them
 * back, mimicking a model that quotes the tokens it was given. The stub never
 * reads $entry->raw — only $entry->token — so it sees no real PII, just like
 * the real model.
 *
 * CRITICAL: the draft references the session's REAL tokens (e.g.
 * <ea0200d1:Email_1>), not hardcoded placeholders like <EMAIL_1>. Only the
 * real tokens survive restore() — a hardcoded placeholder would pass straight
 * through untouched. We degrade gracefully when a class wasn't detected.
 */
function fakeOpenAi(GazeSession $session): string
{
    $person = entryForClass($session, 'name') ?? entryForClass($session, 'person');
    $ssn = entryForClass($session, 'ssn');
    $email = entryForClass($session, 'email');

    $lines = ['Subject: Your details on file', ''];
    $lines[] = $person !== null ? "Hi {$person->token}," : 'Hi there,';
    if ($ssn !== null) {
        $lines[] = "We're confirming the SSN on file as {$ssn->token}.";
    }
    if ($email !== null) {
        $lines[] = "We'll follow up with you at {$email->token}.";
    }
    $lines[] = '';
    $lines[] = 'Best,';
    $lines[] = 'Support';

    return implode("\n", $lines);
}

/**
 * Owner-side action that only makes sense AFTER restore. $to must be a real
 * address; a token like <ea0200d1:Email_1> is not a deliverable destination.
 */
function sendEmail(string $to, string $body): void
{
    echo "→ sending to {$to}\n"; /* real transport (Mail::raw(), an API call, …) goes here */
}

$prompt = 'Email Jane Doe at jane.doe@example.com and confirm her SSN 123-45-6789 is on file.';

$session = Gaze::clean($prompt);     // -> CertaMesh\Gaze\GazeSession (entries + ciphertext stay server-side)

// Trust gate BEFORE anything crosses the model boundary. Don't gate on
// $session->detections — a high count never proves nothing bled through.
// hasSuspectedLeak() is the hard red: upstream's observer-only safety net
// flagged a span that may STILL carry raw PII inside cleanText. That text is
// not trusted to leave your server — stop, alert, keep the prompt owner-side.
if ($session->hasSuspectedLeak()) {
    report(new RuntimeException('gaze flagged a suspected PII leak — prompt withheld from the LLM'));
    echo "→ suspected leak in the cleaned text — nothing sent to the model\n";

    return;
}

// Anything short of Verified is amber, not red: coverage is partial, or there
// was no leak_report to back a green at all. (Through the stock binary the
// Suspect channel is absent, so Unverified is the strongest caution you'll
// see.) Proceeding is a policy call — here we proceed but surface amber
// honestly instead of implying a green we don't have.
if ($session->coverageState() !== CoverageState::Verified) {
    logger()->info('gaze coverage not verified', ['state' => $session->coverageState()->value]);
}

$draft = fakeOpenAi($session);       // the model drafts using ONLY tokens
$final = Gaze::restore($session, $draft); // owner-side: tokens -> real values

echo "ORIGINAL : {$prompt}\n\n";
echo "CLEANED  : {$session->cleanText}\n";        // safe to send to the model
echo "DETECTED : {$session->detections} value(s)\n";
echo 'COVERAGE : '.$session->coverageState()->value."\n\n"; // upstream's verdict on its own redaction
echo "MODEL    : {$draft}\n\n";                   // the model only ever saw tokens
echo "RESTORED : {$final}\n\n";                   // real values back, owner-side

// The send only works AFTER restore. The recipient is the Email entry's real
// `raw` value — held server-side in the session, NEVER sent to the model.
// Pre-restore, the draft addressed the recipient only as a token
// (<...Email_1>), which you cannot email. restore() + the owner-side session
// are what turn the tokenized draft back into a sendable message: that is
// reversibility, and it is the trust contract this package exists to keep.
$emailEntry = entryForClass($session, 'email');
if ($emailEntry !== null) {
    sendEmail($emailEntry->raw, $final);
} else {
    echo "→ no email address detected — nothing to send\n";
}

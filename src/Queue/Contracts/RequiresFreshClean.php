<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Queue\Contracts;

use CertaMesh\Gaze\Exceptions\GazeIntegrityException;

/**
 * Marker for exceptions whose session blob is unrecoverable, signalling that
 * the only recovery is to re-run `Gaze::clean()` on the original text.
 *
 * @deprecated since 0.13 — branch on
 * {@see GazeIntegrityException::requiresFreshClean()} instead:
 * `$e instanceof GazeIntegrityException && $e->requiresFreshClean()`. The
 * method lives on the exception (mirroring the {@see HasRetryDisposition}
 * method-based pattern) and can give variant-dependent answers, which a
 * static marker cannot. The implementing exceptions keep this interface for
 * backwards compatibility until 1.0; nothing in this package dispatches on it.
 */
interface RequiresFreshClean {}

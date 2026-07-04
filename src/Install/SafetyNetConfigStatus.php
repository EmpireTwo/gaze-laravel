<?php

declare(strict_types=1);

namespace CertaMesh\Gaze\Install;

/**
 * Outcome status of a {@see SafetyNetConfigurator::apply()} call.
 *
 *   - {@see self::Written}   — `.env` was mutated (a write-once `.env.backup` exists)
 *   - {@see self::Unchanged} — every key already had the target value; nothing written
 */
enum SafetyNetConfigStatus: string
{
    case Written = 'written';
    case Unchanged = 'unchanged';
}

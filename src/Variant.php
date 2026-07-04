<?php

declare(strict_types=1);

namespace CertaMesh\Gaze;

use CertaMesh\Gaze\Exceptions\GazeAuditPurgeIso8601Exception;
use CertaMesh\Gaze\Exceptions\GazeBlobExpiredException;
use CertaMesh\Gaze\Exceptions\GazeEmptyInputException;
use CertaMesh\Gaze\Exceptions\GazeException;
use CertaMesh\Gaze\Exceptions\GazeInputTooLargeException;
use CertaMesh\Gaze\Exceptions\GazeInvalidBlobVersionException;
use CertaMesh\Gaze\Exceptions\GazeInvalidEncodingException;
use CertaMesh\Gaze\Exceptions\GazeInvalidSignatureException;
use CertaMesh\Gaze\Exceptions\GazeIoException;
use CertaMesh\Gaze\Exceptions\GazePipelineException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigDetailException;
use CertaMesh\Gaze\Exceptions\GazePolicyConfigException;
use CertaMesh\Gaze\Exceptions\GazePolicyOpenException;
use CertaMesh\Gaze\Exceptions\GazePolicySchemaUnsupportedException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetArtifactMissingException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetConfigException;
use CertaMesh\Gaze\Exceptions\GazeSafetyNetFailureException;
use CertaMesh\Gaze\Exceptions\GazeSigPipeException;
use CertaMesh\Gaze\Exceptions\GazeStdinParseException;
use CertaMesh\Gaze\Exceptions\GazeUnknownTokenException;
use CertaMesh\Gaze\Exceptions\GazeUnsupportedSessionScopeException;

enum Variant: string
{
    case StdinParse = 'StdinParse';
    case EmptyInput = 'EmptyInput';
    case InputTooLarge = 'InputTooLarge';
    case InvalidEncoding = 'InvalidEncoding';
    case PolicyConfig = 'PolicyConfig';
    case PolicyConfigDetail = 'PolicyConfigDetail';
    case PolicySchemaUnsupported = 'PolicySchemaUnsupported';
    case SafetyNetConfig = 'SafetyNetConfig';
    case SafetyNet = 'SafetyNet';
    case SafetyNetArtifactMissing = 'SafetyNetArtifactMissing';
    case AuditPurgeIso8601 = 'AuditPurgeIso8601';
    case UnknownToken = 'UnknownToken';
    case UnsupportedSessionScope = 'UnsupportedSessionScope';
    case InvalidSignature = 'InvalidSignature';
    case InvalidBlobVersion = 'InvalidBlobVersion';
    case BlobExpired = 'BlobExpired';
    case Pipeline = 'Pipeline';
    case Io = 'Io';
    case SigPipe = 'SigPipe';
    case PolicyOpen = 'PolicyOpen';

    public static function tryFromStderr(string $stderr, int $actualExit): self
    {
        /** @var array{error?: mixed, exit?: mixed}|null $decoded */
        $decoded = json_decode($stderr, true);

        if (! is_array($decoded)) {
            return self::unknownFor($actualExit);
        }

        $error = $decoded['error'] ?? null;
        $embeddedExit = $decoded['exit'] ?? null;

        if (! is_string($error) || ! is_int($embeddedExit) || $embeddedExit !== $actualExit) {
            return self::unknownFor($actualExit);
        }

        // Upstream collapses PolicyConfig and PolicyConfigDetail under the same wire
        // name (`crates/gaze-cli/src/error.rs:39-55`) and disambiguates only via the
        // `detail` sidecar. The synthetic PolicyConfigDetail backing value never
        // appears on stderr; this branch is the only path that resolves it.
        if ($error === 'PolicyConfig' && array_key_exists('detail', $decoded)) {
            return self::PolicyConfigDetail;
        }

        if ($error === 'SafetyNetConfig' && array_key_exists('detail', $decoded)) {
            return self::SafetyNetConfig;
        }

        if ($error === 'SafetyNet' && isset($decoded['variant']) && is_string($decoded['variant'])) {
            return self::SafetyNet;
        }

        if ($error === 'UnsupportedSessionScope' && isset($decoded['variant']) && is_string($decoded['variant'])) {
            return self::UnsupportedSessionScope;
        }

        return self::tryFrom($error) ?? self::unknownFor($actualExit);
    }

    public static function unknownFor(int $exit): self
    {
        return match ($exit) {
            2 => self::PolicyConfig,
            3 => self::UnknownToken,
            4 => self::Io,
            141 => self::SigPipe,
            // Exit 1 is a caller-bug bucket that can carry several shape errors.
            // When stderr is missing, StdinParse is the most conservative tie-break.
            default => self::StdinParse,
        };
    }

    /**
     * Construct the typed exception for this variant.
     *
     * Message shape is a stable contract:
     * `gaze {stage} {summary} (exit={exit}, stderr_sha256={hash})`.
     * The raw stderr is only consulted for safelisted sidecar fields
     * (`detail`, `found`, `supported`, `variant`, `backend`, `path`) — it is
     * NEVER copied into the exception message or log context (PII discipline).
     */
    public function toException(string $stage, int $exitCode, string $stderrHash, string $stderr = ''): GazeException
    {
        [$class, $summary] = $this->exceptionSpec();
        $message = "gaze {$stage} {$summary} (exit={$exitCode}, stderr_sha256={$stderrHash})";

        // Variants that carry upstream sidecar fields need bespoke constructor
        // arguments; every other variant shares the standard signature.
        return match ($this) {
            self::PolicyConfigDetail => new GazePolicyConfigDetailException(
                $message,
                $exitCode,
                $stderrHash,
                self::stderrStringField($stderr, 'detail'),
            ),
            self::PolicySchemaUnsupported => new GazePolicySchemaUnsupportedException(
                $message,
                $exitCode,
                $stderrHash,
                self::stderrStringField($stderr, 'found') ?? '',
                self::stderrStringField($stderr, 'supported') ?? '',
            ),
            self::SafetyNet => new GazeSafetyNetFailureException(
                $message,
                $exitCode,
                $stderrHash,
                self::stderrStringField($stderr, 'variant') ?? 'Other',
            ),
            self::SafetyNetArtifactMissing => new GazeSafetyNetArtifactMissingException(
                $message,
                $exitCode,
                $stderrHash,
                self::stderrStringField($stderr, 'backend') ?? '',
                self::stderrStringField($stderr, 'path') ?? '',
            ),
            self::UnsupportedSessionScope => new GazeUnsupportedSessionScopeException(
                $message,
                $exitCode,
                $stderrHash,
                self::stderrStringField($stderr, 'variant') ?? '',
            ),
            default => new $class($message, $exitCode, $stderrHash),
        };
    }

    /**
     * Variant → [exception class, message summary] table. A new upstream
     * variant is a one-line addition here (plus its enum case); the message
     * summaries are a pinned contract — do not reword existing entries.
     *
     * @return array{0: class-string<GazeException>, 1: string}
     */
    private function exceptionSpec(): array
    {
        return match ($this) {
            self::StdinParse => [GazeStdinParseException::class, 'stdin parse failed'],
            self::EmptyInput => [GazeEmptyInputException::class, 'input was empty'],
            self::InputTooLarge => [GazeInputTooLargeException::class, 'input exceeded max bytes'],
            self::InvalidEncoding => [GazeInvalidEncodingException::class, 'input encoding invalid'],
            self::PolicyConfig => [GazePolicyConfigException::class, 'policy configuration invalid'],
            self::PolicyConfigDetail => [GazePolicyConfigDetailException::class, 'policy configuration invalid'],
            self::PolicySchemaUnsupported => [GazePolicySchemaUnsupportedException::class, 'policy schema version unsupported'],
            self::SafetyNetConfig => [GazeSafetyNetConfigException::class, 'safety-net configuration invalid'],
            self::SafetyNet => [GazeSafetyNetFailureException::class, 'safety-net failed'],
            self::SafetyNetArtifactMissing => [GazeSafetyNetArtifactMissingException::class, 'safety-net artifact missing'],
            self::AuditPurgeIso8601 => [GazeAuditPurgeIso8601Exception::class, 'audit purge timestamp not ISO8601'],
            self::UnknownToken => [GazeUnknownTokenException::class, 'encountered unknown token'],
            self::UnsupportedSessionScope => [GazeUnsupportedSessionScopeException::class, 'session scope unsupported'],
            self::InvalidSignature => [GazeInvalidSignatureException::class, 'session signature invalid'],
            self::InvalidBlobVersion => [GazeInvalidBlobVersionException::class, 'blob version invalid'],
            self::BlobExpired => [GazeBlobExpiredException::class, 'blob expired'],
            self::Pipeline => [GazePipelineException::class, 'pipeline failed'],
            self::Io => [GazeIoException::class, 'I/O failed'],
            self::SigPipe => [GazeSigPipeException::class, 'terminated by SIGPIPE'],
            self::PolicyOpen => [GazePolicyOpenException::class, 'policy open failed'],
        };
    }

    /**
     * Safelisted sidecar-field extraction from the upstream stderr JSON
     * envelope. Returns null when stderr is not JSON or the field is absent.
     */
    private static function stderrStringField(string $stderr, string $field): ?string
    {
        $decoded = json_decode($stderr, true);

        if (! is_array($decoded)) {
            return null;
        }

        $value = $decoded[$field] ?? null;

        return is_string($value) ? $value : null;
    }

    public function exitBucket(): int
    {
        return match ($this) {
            self::StdinParse, self::EmptyInput, self::InputTooLarge, self::InvalidEncoding => 1,
            self::PolicyConfig, self::PolicyConfigDetail, self::PolicySchemaUnsupported, self::SafetyNetArtifactMissing, self::AuditPurgeIso8601 => 2,
            self::SafetyNetConfig,
            self::SafetyNet,
            self::UnknownToken,
            self::UnsupportedSessionScope,
            self::InvalidSignature,
            self::InvalidBlobVersion,
            self::BlobExpired,
            self::Pipeline => 3,
            self::Io, self::PolicyOpen => 4,
            self::SigPipe => 141,
        };
    }
}

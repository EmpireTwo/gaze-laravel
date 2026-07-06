<?php

declare(strict_types=1);

use CertaMesh\Gaze\Install\BinaryDownloader;
use CertaMesh\Gaze\Install\BinaryInstaller;
use Composer\IO\BufferIO;

beforeEach(function () {
    // Hermetic env baseline. The postInstall/install pipeline reads GAZE_VERSION,
    // GAZE_SKIP_BINARY_DOWNLOAD, GAZE_RELEASE_BASE, GAZE_GITHUB_TOKEN and APP_ENV
    // from the ambient environment. An inherited GAZE_VERSION that differs from
    // PINNED_VERSION (as the prefer-lowest CI leg's stale "0.11.1" literal did,
    // and as a stray shell export can on a dev host) desyncs the "already
    // installed" short-circuit and drops these tests onto the real network path —
    // deterministically red in CI, nondeterministically flaky locally. Reset to a
    // known-clean slate so every test controls only the vars it sets itself.
    foreach (['GAZE_VERSION', 'GAZE_SKIP_BINARY_DOWNLOAD', 'GAZE_RELEASE_BASE', 'GAZE_GITHUB_TOKEN', 'APP_ENV'] as $var) {
        putenv($var);
    }

    $this->tmpDir = sys_get_temp_dir().'/gaze-laravel-installer-'.bin2hex(random_bytes(6));
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    gl_recursiveRemove($this->tmpDir);
});

it('detects a supported target triple on macOS or Linux', function () {
    $target = BinaryDownloader::detectTarget();

    if ($target === null) {
        $this->markTestSkipped('running on an unsupported platform');
    }

    expect($target)->toMatch('/^(aarch64-apple-darwin|x86_64-linux-gnu)$/');
});

it('returns false from alreadyInstalled when binary is missing', function () {
    expect(BinaryDownloader::alreadyInstalled($this->tmpDir.'/nope', '0.1.0'))->toBeFalse();
});

it('matches alreadyInstalled only when the version output token is exactly the version', function () {
    $path = gl_makeProcessFixture(
        $this->tmpDir,
        'gaze',
        "echo 'gaze 0.3.0-rc.3'.PHP_EOL;",
    );

    expect(BinaryDownloader::alreadyInstalled($path, '0.3.0-rc.3'))->toBeTrue()
        ->and(BinaryDownloader::alreadyInstalled($path, '0.3.0'))->toBeFalse()
        ->and(BinaryDownloader::alreadyInstalled($path, '0.4.0'))->toBeFalse();
});

it('does not let a longer installed version substring-satisfy a shorter pin', function () {
    // `str_contains('gaze 0.11.10', '0.11.1')` is true — the exact-token
    // compare must reject it so the pinned 0.11.1 download still happens.
    $path = gl_makeProcessFixture(
        $this->tmpDir,
        'gaze',
        "echo 'gaze 0.11.10'.PHP_EOL;",
    );

    expect(BinaryDownloader::alreadyInstalled($path, '0.11.1'))->toBeFalse()
        ->and(BinaryDownloader::alreadyInstalled($path, '0.11.10'))->toBeTrue();
});

it('returns false from alreadyInstalled when version probe exits non-zero', function () {
    $path = gl_makeProcessFixture(
        $this->tmpDir,
        'gaze',
        "fwrite(STDERR, 'probe failed'.PHP_EOL);\nexit(1);",
    );

    expect(BinaryDownloader::alreadyInstalled($path, '0.3.0'))->toBeFalse();
});

it('accepts a matching sha256 in verifyChecksum', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    $contents = 'fake tarball bytes';
    file_put_contents($tar, $contents);
    $sha = hash('sha256', $contents);

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents(
        $sums,
        "0000000000000000000000000000000000000000000000000000000000000000  other.tar.gz\n"
        ."{$sha} *payload.tar.gz\n",
    );

    BinaryDownloader::verifyChecksum($tar, $sums, 'payload.tar.gz');
    expect($tar)->toBeFile();
});

it('rejects a sha256 mismatch in verifyChecksum', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    file_put_contents($tar, 'real bytes');

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents(
        $sums,
        "0000000000000000000000000000000000000000000000000000000000000000  payload.tar.gz\n",
    );

    BinaryDownloader::verifyChecksum($tar, $sums, 'payload.tar.gz');
})->throws(RuntimeException::class, 'sha256 mismatch for payload.tar.gz');

it('requires a checksum entry for the asset', function () {
    $tar = $this->tmpDir.'/payload.tar.gz';
    file_put_contents($tar, 'x');

    $sums = $this->tmpDir.'/SHA256SUMS';
    file_put_contents($sums, "# empty\n");

    BinaryDownloader::verifyChecksum($tar, $sums, 'payload.tar.gz');
})->throws(RuntimeException::class, 'no checksum entry for payload.tar.gz');

it('honors GAZE_SKIP_BINARY_DOWNLOAD', function () {
    putenv('GAZE_SKIP_BINARY_DOWNLOAD=1');
    try {
        $io = new BufferIO;
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('skipping binary download')
            ->and($this->tmpDir.'/gaze')->not->toBeFile();
    } finally {
        putenv('GAZE_SKIP_BINARY_DOWNLOAD');
    }
});

it('refuses a non-HTTPS release base', function () {
    putenv('APP_ENV=testing');
    putenv('GAZE_RELEASE_BASE=http://example.com/insecure');
    try {
        $io = new BufferIO;
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect($io->getOutput())->toContain('non-HTTPS')
            ->and($io->getOutput())->toContain('non-canonical GAZE_RELEASE_BASE override')
            ->and($this->tmpDir.'/gaze')->not->toBeFile();
    } finally {
        putenv('APP_ENV');
        putenv('GAZE_RELEASE_BASE');
    }
});

it('ignores GAZE_RELEASE_BASE in production installs', function () {
    $binPath = $this->tmpDir.'/gaze';
    $version = BinaryInstaller::PINNED_VERSION;
    file_put_contents($binPath, "#!/bin/sh\necho 'gaze {$version}'\n");
    chmod($binPath, 0755);

    putenv('APP_ENV=production');
    putenv('GAZE_RELEASE_BASE=https://attacker.example/releases/download');
    try {
        $io = new BufferIO;
        BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

        expect(BinaryInstaller::resolveReleaseBase(new BufferIO))->toBe('https://github.com/CertaMesh/gaze/releases/download')
            ->and($io->getOutput())->toContain("gaze v{$version} already installed")
            ->and($io->getOutput())->not->toContain('non-canonical GAZE_RELEASE_BASE override');
    } finally {
        putenv('APP_ENV');
        putenv('GAZE_RELEASE_BASE');
    }
});

it('honors GAZE_RELEASE_BASE outside production and logs the override', function () {
    putenv('APP_ENV=staging');
    putenv('GAZE_RELEASE_BASE=https://fixtures.example/releases/download');
    try {
        $io = new BufferIO;

        expect(BinaryInstaller::resolveReleaseBase($io))->toBe('https://fixtures.example/releases/download')
            ->and($io->getOutput())->toContain('non-canonical GAZE_RELEASE_BASE override');
    } finally {
        putenv('APP_ENV');
        putenv('GAZE_RELEASE_BASE');
    }
});

it('parses status and Location header', function () {
    $headers = [
        'HTTP/1.1 302 Found',
        'Server: nginx',
        'Location: https://objects.example.com/foo.tar.gz',
        'Content-Length: 0',
    ];

    [$status, $location] = BinaryDownloader::parseStatusAndLocation($headers);

    expect($status)->toBe(302)
        ->and($location)->toBe('https://objects.example.com/foo.tar.gz');
});

it('keeps the final Location when multiple HTTP status lines appear', function () {
    $headers = [
        'HTTP/1.1 100 Continue',
        'HTTP/1.1 200 OK',
        'Content-Type: application/octet-stream',
    ];

    [$status, $location] = BinaryDownloader::parseStatusAndLocation($headers);

    expect($status)->toBe(200)
        ->and($location)->toBeNull();
});

it('resolves absolute redirect URLs unchanged', function () {
    expect(BinaryDownloader::resolveLocation(
        'https://github.com/x/y/z',
        'https://cdn.example.com/blob',
    ))->toBe('https://cdn.example.com/blob');
});

it('resolves root-relative redirects onto the origin scheme and host', function () {
    expect(BinaryDownloader::resolveLocation(
        'https://github.com/x/y/z',
        '/download/artifact.tar.gz',
    ))->toBe('https://github.com/download/artifact.tar.gz');
});

it('resolves path-relative redirects against the current directory', function () {
    expect(BinaryDownloader::resolveLocation(
        'https://example.com/a/b/c',
        'd.tar.gz',
    ))->toBe('https://example.com/a/b/d.tar.gz');
});

it('derives github owner/repo from the default release base', function () {
    $base = 'https://github.com/CertaMesh/gaze/releases/download';

    expect(BinaryDownloader::deriveGithubRepo($base))->toBe('CertaMesh/gaze');
});

it('derives github owner/repo when the base has a trailing path segment', function () {
    $base = 'https://github.com/CertaMesh/gaze/releases/download/v0.3.0';

    expect(BinaryDownloader::deriveGithubRepo($base))->toBe('CertaMesh/gaze');
});

it('returns null for non-github release bases', function () {
    expect(BinaryDownloader::deriveGithubRepo('https://mirror.internal/gaze/releases/download'))->toBeNull()
        ->and(BinaryDownloader::deriveGithubRepo('https://example.com/foo/bar'))->toBeNull()
        ->and(BinaryDownloader::deriveGithubRepo('http://github.com/CertaMesh/gaze/releases/download'))->toBeNull();
});

it('builds unauthenticated request headers without an Authorization line', function () {
    $headers = BinaryDownloader::buildRequestHeaders(null, 'application/octet-stream');

    expect($headers)->toContain('Accept: application/octet-stream')
        ->and($headers)->toContain('User-Agent: gaze-laravel/'.BinaryInstaller::PINNED_VERSION);

    foreach ($headers as $line) {
        expect(stripos($line, 'Authorization:'))->toBeFalse();
        expect(stripos($line, 'X-GitHub-Api-Version:'))->toBeFalse();
    }
});

it('builds authenticated request headers with Bearer auth and the api version pin', function () {
    $headers = BinaryDownloader::buildRequestHeaders('ghp_testtoken123', 'application/vnd.github+json');

    expect($headers)->toContain('Accept: application/vnd.github+json')
        ->and($headers)->toContain('User-Agent: gaze-laravel/'.BinaryInstaller::PINNED_VERSION)
        ->and($headers)->toContain('Authorization: Bearer ghp_testtoken123')
        ->and($headers)->toContain('X-GitHub-Api-Version: 2022-11-28');

    // GitHub deprecated `Authorization: token <pat>` — verify we are NOT using that form.
    foreach ($headers as $line) {
        expect(str_starts_with($line, 'Authorization: token '))->toBeFalse();
    }
});

it('treats an empty token as unauthenticated when building headers', function () {
    $headers = BinaryDownloader::buildRequestHeaders('', 'application/octet-stream');

    foreach ($headers as $line) {
        expect(stripos($line, 'Authorization:'))->toBeFalse();
    }
});

it('keeps Authorization when redirect stays on the same host', function () {
    $headers = [
        'User-Agent: gaze-laravel/0.3.0',
        'Authorization: Bearer ghp_secret',
        'Accept: application/octet-stream',
    ];

    $result = BinaryDownloader::stripAuthOnCrossHost($headers, 'api.github.com', 'api.github.com');

    expect($result)->toBe($headers);
});

it('strips Authorization when redirect crosses to a different host (S3)', function () {
    $headers = [
        'User-Agent: gaze-laravel/0.3.0',
        'Authorization: Bearer ghp_secret',
        'Accept: application/octet-stream',
    ];

    $result = BinaryDownloader::stripAuthOnCrossHost($headers, 'api.github.com', 'objects.githubusercontent.com');

    expect($result)->toBe([
        'User-Agent: gaze-laravel/0.3.0',
        'Accept: application/octet-stream',
    ]);
});

it('strips Authorization defensively when host parsing fails', function () {
    $headers = [
        'Authorization: Bearer ghp_secret',
        'User-Agent: gaze-laravel/0.3.0',
    ];

    $result = BinaryDownloader::stripAuthOnCrossHost($headers, 'api.github.com', null);

    expect($result)->toBe(['User-Agent: gaze-laravel/0.3.0']);
});

it('extracts asset id pair from a github releases tag JSON payload', function () {
    $json = json_encode([
        'tag_name' => 'v0.3.0',
        'assets' => [
            ['id' => 111, 'name' => 'other-thing'],
            ['id' => 222, 'name' => 'gaze-aarch64-apple-darwin'],
            ['id' => 333, 'name' => 'gaze-aarch64-apple-darwin.sha256'],
            ['id' => 444, 'name' => 'gaze-x86_64-linux-gnu'],
        ],
    ]);

    [$assetId, $sumsId] = BinaryDownloader::extractAssetIds(
        (string) $json,
        'gaze-aarch64-apple-darwin',
        'v0.3.0',
    );

    expect($assetId)->toBe(222)
        ->and($sumsId)->toBe(333);
});

it('throws when the github releases tag JSON has no assets array', function () {
    BinaryDownloader::extractAssetIds('{"message":"Not Found"}', 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'invalid JSON or no assets[]');

it('throws when the asset name is missing from the release', function () {
    $json = (string) json_encode([
        'assets' => [
            ['id' => 1, 'name' => 'gaze-x86_64-linux-gnu'],
            ['id' => 2, 'name' => 'gaze-x86_64-linux-gnu.sha256'],
        ],
    ]);

    BinaryDownloader::extractAssetIds($json, 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'asset gaze-aarch64-apple-darwin not found');

it('throws when the .sha256 sidecar asset is missing from the release', function () {
    $json = (string) json_encode([
        'assets' => [
            ['id' => 1, 'name' => 'gaze-aarch64-apple-darwin'],
        ],
    ]);

    BinaryDownloader::extractAssetIds($json, 'gaze-aarch64-apple-darwin', 'v0.3.0');
})->throws(RuntimeException::class, 'asset gaze-aarch64-apple-darwin.sha256 not found');

it('short-circuits when the binary is already at the pinned version', function () {
    $version = BinaryInstaller::PINNED_VERSION;
    $binPath = $this->tmpDir.'/gaze';
    file_put_contents(
        $binPath,
        "#!/bin/sh\n"
        ."if [ \"\${1:-}\" = '--version' ]; then\n"
        ."    printf '%s\n' 'gaze {$version}'\n"
        ."    exit 0\n"
        ."fi\n"
        ."printf '%s\n' \"unexpected arguments: \$*\" >&2\n"
        ."exit 1\n",
    );
    chmod($binPath, 0755);

    $io = new BufferIO;
    BinaryInstaller::postInstall(gl_makeEvent($io, $this->tmpDir));

    expect($io->getOutput())->toContain("gaze v{$version} already installed")
        ->and($binPath)->toBeFile();
});

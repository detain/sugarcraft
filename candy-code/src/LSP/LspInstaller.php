<?php

declare(strict_types=1);

namespace SugarCraft\Code\LSP;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\PrivateDir;
use SugarCraft\Code\Tools\Concerns\CapturesProcessOutput;

/**
 * Installs language servers the user explicitly asked for (C-TOOL-5.c) —
 * never run automatically, never run during launch, never run "to fix" a
 * missing one. The CLI verb that reaches this class arrives in the next step;
 * everything here is behind that call.
 *
 * THE ONE LAW: every byte fetched is pinned. {@see LspReleases} is the only
 * source of URLs and versions; {@see LspReleases::isAllowedUrl()} re-checks
 * each one at use time, every redirect hop is re-checked by
 * {@see redirectGuard()}, and the downloaded asset is hash-verified against
 * its pinned sha256 BEFORE anything touches it (decompression runs only on
 * checksummed bytes). The npm arm never fetches from a URL this file names at
 * all — it runs `npm --ignore-scripts` with a pinned version, so lifecycle
 * scripts (the arbitrary-code door of the npm path) stay shut.
 *
 * NO BLANKET REQUEST TIMEOUT (E646). The download client carries a connect
 * ceiling and a per-read ceiling, mirroring {@see \SugarCraft\Code\Cli\SelfUpdate}
 * and the reasoning recorded there: a slow-but-progressing transfer must be
 * allowed to finish. What bounds total size instead is
 * {@see MAX_DOWNLOAD_BYTES} through the progress arm, and the npm arm's
 * ceiling is a child-process bound, not a request timeout.
 *
 * LAYOUT, all under {@see installRoot()} (the home-private `.candy-code/lsp`
 * tree, 0700 via {@see PrivateDir}): binaries land in `bin/<name>`, npm
 * servers in `node_modules/.bin/`. {@see LspLauncher}'s auto-detect consults
 * exactly these two directories ahead of PATH, so what lands here is what
 * launches there.
 */
final class LspInstaller
{
    use CapturesProcessOutput;

    /** Home-relative directory tree this installer owns. */
    public const INSTALL_SUBPATH = '.candy-code/lsp';

    /** Connection ceiling for release downloads, seconds. */
    public const CONNECT_TIMEOUT_SECONDS = 5.0;

    /** Per-read ceiling for release downloads, seconds. */
    public const READ_TIMEOUT_SECONDS = 60.0;

    /** Largest release asset accepted (512 MiB), enforced by {@see progressGuard()}. */
    public const MAX_DOWNLOAD_BYTES = 536870912;

    /**
     * Child-process wall bound for the npm install, seconds. NOT an HTTP
     * request timeout — E646's ban is on blanket total-request ceilings for
     * provider traffic, and this bounds a local process the same way every
     * other captured command in the tool set is bounded.
     */
    public const NPM_TIMEOUT_SECONDS = 300.0;

    /** Distinct identity on the wire (SelfUpdate's phar download sets its own). */
    public const USER_AGENT = 'candy-code-lsp-install';

    /** curl's own stalled-transfer pair, mirroring the phar downloader. */
    private const LOW_SPEED_LIMIT_BYTES_PER_SEC = 1024;

    private const LOW_SPEED_TIME_SECONDS = 60;

    private function __construct()
    {
    }

    /**
     * The install tree root, or null when no private home exists (a sandbox
     * without one simply cannot install; every caller refuses loudly).
     */
    public static function installRoot(): ?string
    {
        $home = HomeDirectory::owned();

        return $home === null ? null : $home . '/' . self::INSTALL_SUBPATH;
    }

    /**
     * Absolute path of $binary inside the install tree's launch order —
     * `bin/` first (the downloaded binaries), then npm's `node_modules/.bin`
     * — or null when neither carries it executably. This is the SAME probe
     * {@see LspLauncher}'s production lookup runs, so the install site and
     * the launch site can never disagree about what is installed.
     */
    public static function installedBinaryPath(string $binary, ?string $installRoot = null): ?string
    {
        if ($installRoot === null) {
            $installRoot = self::installRoot();
        }
        if ($installRoot === null || $binary === '' || strpos($binary, '/') !== false) {
            return null;
        }
        foreach ([$installRoot . '/bin/' . $binary, $installRoot . '/node_modules/.bin/' . $binary] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Install the server for one language.
     *
     * @param ?Client   $http        seam for the release download
     * @param ?string   $installRoot seam for the tree location
     * @param ?callable $run         seam for the npm child process
     * @param ?string   $os          target os key ("linux"/"darwin")
     * @param ?string   $arch        target arch key ("x86_64"/"aarch64")
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    public static function install(
        string $language,
        ?Client $http = null,
        ?string $installRoot = null,
        ?callable $run = null,
        ?string $os = null,
        ?string $arch = null,
    ): array {
        $installRoot ??= self::installRoot();
        if ($installRoot === null) {
            return self::failure('lsp install: no private home directory is available to install into');
        }

        $npm = LspReleases::npmEntry($language);
        if ($npm !== null) {
            return self::installNpm($npm, $installRoot, $run);
        }

        $os ??= strcasecmp(PHP_OS_FAMILY, 'Darwin') === 0 ? 'darwin' : 'linux';
        $arch ??= self::normaliseArch(php_uname('m'));
        $entry = LspReleases::binaryEntry($language, $os, $arch);
        if ($entry === null) {
            return self::failure(sprintf(
                'lsp install: no pinned %s release for %s/%s (Windows, musl and 32-bit builds are not published here; go servers need a go toolchain)',
                $language,
                $os,
                $arch,
            ));
        }

        return self::installBinary($entry, $installRoot, $http ?? new Client(self::clientOptions()));
    }

    /**
     * Options shared by every release download: the E646-shaped ceilings
     * (connect + per-read, deliberately NO total-request bound) and the
     * stalled-transfer floor.
     *
     * @return array<string, mixed>
     */
    public static function clientOptions(): array
    {
        $options = [
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_timeout' => self::READ_TIMEOUT_SECONDS,
            'http_errors' => false,
            'headers' => [
                'Accept' => 'application/octet-stream',
                'User-Agent' => self::USER_AGENT,
            ],
        ];
        if (\defined('CURLOPT_LOW_SPEED_LIMIT') && \defined('CURLOPT_LOW_SPEED_TIME')) {
            $options['curl'] = [
                \constant('CURLOPT_LOW_SPEED_LIMIT') => self::LOW_SPEED_LIMIT_BYTES_PER_SEC,
                \constant('CURLOPT_LOW_SPEED_TIME') => self::LOW_SPEED_TIME_SECONDS,
            ];
        }

        return $options;
    }

    /**
     * Per-request options for one download: the sink, the size guard, and the
     * redirect re-check. Assembled here (not inline) so a test can read the
     * exact law the wire sees: ceilings in, total-request timeout out, every
     * hop gated.
     *
     * @return array<string, mixed>
     */
    public static function downloadOptions(string $sink): array
    {
        return self::clientOptions() + [
            'sink' => $sink,
            'progress' => self::progressGuard(self::MAX_DOWNLOAD_BYTES),
            'on-redirect' => self::redirectGuard(),
        ];
    }

    /**
     * Guzzle progress callback refusing a transfer that exceeds $maxBytes in
     * EITHER accounting: the server's claimed Content-Length or the bytes
     * actually streamed.
     */
    public static function progressGuard(int $maxBytes): \Closure
    {
        return static function (int $expectedBytes, int $downloadedBytes) use ($maxBytes): void {
            if ($downloadedBytes > $maxBytes || ($expectedBytes > 0 && $expectedBytes > $maxBytes)) {
                throw new \RuntimeException(sprintf(
                    'lsp install: download exceeded the %d byte ceiling',
                    $maxBytes,
                ));
            }
        };
    }

    /**
     * Redirect callback re-running the allowlist on every hop a response
     * sends us to — the pinned first URL may not leg into an unpinned second.
     */
    public static function redirectGuard(): \Closure
    {
        return static function (RequestInterface $request): void {
            if (!LspReleases::isAllowedUrl((string) $request->getUri())) {
                throw new \RuntimeException('lsp install: a redirect left the pinned release allowlist');
            }
        };
    }

    /**
     * The EXACT npm command line for a pinned entry (a pure builder so the law
     * is testable without a child process): private prefix, no lifecycle
     * scripts, pinned version, registry spelled out.
     */
    public static function npmCommand(string $installRoot, string $package, string $version): string
    {
        return implode(' ', [
            'npm',
            'install',
            '--no-save',
            '--no-audit',
            '--no-fund',
            '--ignore-scripts',
            '--prefix ' . escapeshellarg($installRoot),
            '--registry ' . escapeshellarg('https://registry.npmjs.org'),
            escapeshellarg($package . '@' . $version),
        ]);
    }

    /**
     * @param array{package: string, version: string, binary: string} $npm
     * @param ?callable(?string): array{exitCode: int, stderr: string, timedOut: bool} $run
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    private static function installNpm(array $npm, string $installRoot, ?callable $run): array
    {
        try {
            PrivateDir::ensure($installRoot, 'lsp install directory');
        } catch (\RuntimeException $reason) {
            return self::failure($reason->getMessage());
        }
        $installer = new self();
        $command = self::npmCommand($installRoot, $npm['package'], $npm['version']);
        $outcome = $run !== null
            ? $run($command)
            : $installer->captureNpm($command, $installRoot);

        if (($outcome['timedOut'] ?? false) === true) {
            return self::failure(sprintf(
                'lsp install: npm install of %s exceeded its %d second bound',
                $npm['package'],
                self::NPM_TIMEOUT_SECONDS,
            ));
        }
        $binary = $installRoot . '/node_modules/.bin/' . $npm['binary'];
        if (($outcome['exitCode'] ?? 1) !== 0) {
            return self::failure(sprintf(
                'lsp install: npm install of %s failed (exit %d)%s',
                $npm['package'],
                (int) ($outcome['exitCode'] ?? 1),
                self::stderrTail((string) ($outcome['stderr'] ?? '')),
            ));
        }
        if (!is_file($binary) || !is_executable($binary)) {
            return self::failure(sprintf(
                'lsp install: npm finished but %s did not appear in node_modules/.bin',
                $npm['binary'],
            ));
        }

        return ['ok' => true, 'message' => sprintf('installed %s %s', $npm['package'], $npm['version']), 'path' => $binary];
    }

    /**
     * @param array{url: string, asset: string, sha256: string, binary: string} $entry
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    private static function installBinary(array $entry, string $installRoot, ClientInterface $http): array
    {
        if (!LspReleases::isAllowedUrl($entry['url'])) {
            return self::failure('lsp install: the manifest handed out a URL outside the allowlist; refusing it');
        }

        $binDir = $installRoot . '/bin';
        try {
            PrivateDir::ensure($installRoot, 'lsp install directory');
            PrivateDir::ensure($binDir, 'lsp install directory');
        } catch (\RuntimeException $reason) {
            return self::failure($reason->getMessage());
        }

        $temp = $binDir . '/.dl-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.gz';
        try {
            // http_errors is off, so any status reaches the check below as a
            // plain response — the mirror of the phar downloader's fetchOk.
            $response = $http->get($entry['url'], self::downloadOptions($temp));
            if (!$response instanceof ResponseInterface || $response->getStatusCode() >= 400) {
                $status = $response instanceof ResponseInterface ? $response->getStatusCode() : 0;

                return self::failure(sprintf('lsp install: %s answered HTTP %d', $entry['asset'], $status));
            }

            // Checksum FIRST: nothing decompresses or executes until the
            // bytes match the pinned hash of the asset as published.
            $actual = is_file($temp) ? hash_file('sha256', $temp) : false;
            if (!\is_string($actual) || !hash_equals($entry['sha256'], $actual)) {
                return self::failure(sprintf('lsp install: checksum mismatch for %s', $entry['asset']));
            }

            $target = $binDir . '/' . $entry['binary'];
            $staged = $temp . '.out';
            $in = @fopen('compress.zlib://' . $temp, 'rb');
            if ($in === false) {
                return self::failure(sprintf('lsp install: %s is not readable as gzip', $entry['asset']));
            }
            $out = @fopen($staged, 'wb');
            if ($out === false) {
                return self::failure('lsp install: the staged binary could not be written');
            }
            try {
                stream_copy_to_stream($in, $out);
            } finally {
                fclose($in);
                fclose($out);
            }
            if (@chmod($staged, 0o755) === false) {
                @unlink($staged);

                return self::failure('lsp install: the staged binary could not be made executable');
            }
            if (!@rename($staged, $target)) {
                @unlink($staged);

                return self::failure('lsp install: the verified binary could not be moved into place');
            }

            return ['ok' => true, 'message' => sprintf('installed %s', $entry['binary']), 'path' => $target];
        } catch (TransferException $failure) {
            return self::failure('lsp install: the release download failed: ' . $failure->getMessage());
        } catch (\RuntimeException $guard) {
            // progress + redirect guards refuse through this arm
            return self::failure($guard->getMessage());
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array{exitCode: int, stderr: string, timedOut: bool}
     */
    private function captureNpm(string $command, string $installRoot): array
    {
        $outcome = $this->runCaptured(
            $command,
            \dirname($installRoot),
            null,
            self::NPM_TIMEOUT_SECONDS,
            ['npm_config_cache' => $installRoot . '/.npm-cache'],
        );

        return [
            'exitCode' => (int) $outcome['exitCode'],
            'stderr' => (string) $outcome['stderr'],
            'timedOut' => (bool) $outcome['timedOut'],
        ];
    }

    private static function normaliseArch(string $machine): string
    {
        $lower = strtolower($machine);
        if ($lower === 'amd64' || $lower === 'x86_64' || $lower === 'x64') {
            return 'x86_64';
        }
        if ($lower === 'arm64' || $lower === 'aarch64') {
            return 'aarch64';
        }

        return $lower;
    }

    private static function stderrTail(string $stderr): string
    {
        $trimmed = trim($stderr);
        if ($trimmed === '') {
            return '';
        }
        $clip = strlen($trimmed) > 300 ? '…' . substr($trimmed, -299) : $trimmed;

        return ': ' . $clip;
    }

    /**
     * @return array{ok: bool, message: string, path: ?string}
     */
    private static function failure(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'path' => null];
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\LSP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use ReflectionMethod;
use SugarCraft\Code\LSP\LspInstaller;
use SugarCraft\Code\LSP\LspReleases;

/**
 * C-TOOL-5.c: the pinned LSP server installer. Three laws get pinned here —
 * every fetch URL comes from the release manifest and re-passes the
 * allowlist at use time; downloaded bytes are checksum-verified BEFORE
 * anything decompresses or executes; and the npm arm runs with
 * --ignore-scripts at pinned versions through the existing captured-child
 * seam only. Fake handlers (the SelfUpdateTest idiom) replace the wire; no
 * test here touches a real network, a real PATH, or npm itself.
 */
final class LspInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc-lsp-inst-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->pruneInstallerTrees();
        @rmdir($this->root);
    }

    // ── the manifest itself ────────────────────────────────────────────

    public function testEveryPinnedBinaryLinePassesItsOwnGate(): void
    {
        $this->assertSame(['php', 'typescript', 'python', 'rust'], LspReleases::installableLanguages());
        foreach ([['linux', 'x86_64'], ['linux', 'aarch64'], ['darwin', 'x86_64'], ['darwin', 'aarch64']] as [$os, $arch]) {
            $entry = LspReleases::binaryEntry('rust', $os, $arch);
            $this->assertNotNull($entry, $os . '/' . $arch);
            $this->assertTrue(LspReleases::isAllowedUrl($entry['url']), $entry['url']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $entry['sha256'], 'a pin must be a full lowercase sha256');
            $this->assertStringContainsString(LspReleases::RUST_ANALYZER_RELEASE_TAG, $entry['url']);
            $this->assertSame('rust-analyzer', $entry['binary']);
        }
        $this->assertSame(
            'https://github.com/rust-lang/rust-analyzer/releases/download/2026-10-26/rust-analyzer-x86_64-unknown-linux-gnu.gz',
            LspReleases::binaryEntry('rust', 'linux', 'x86_64')['url'],
        );
    }

    public function testTheNpmArmPinsPackageVersionAndServerBinary(): void
    {
        $this->assertSame(
            ['package' => 'intelephense', 'version' => '1.18.5', 'binary' => 'intelephense'],
            LspReleases::npmEntry('php'),
        );
        $this->assertSame(
            ['package' => 'typescript-language-server', 'version' => '6.0.2', 'binary' => 'typescript-language-server'],
            LspReleases::npmEntry('typescript'),
        );
        $this->assertSame(
            ['package' => 'pyright', 'version' => '1.1.414', 'binary' => 'pyright-langserver'],
            LspReleases::npmEntry('python'),
            'pyright the package exposes pyright-langserver the command — the launcher table names the latter',
        );
        $this->assertNull(LspReleases::npmEntry('rust'));
        $this->assertNull(LspReleases::npmEntry('go'));
    }

    public function testUnpinnedLanguagesAndTargetsAnswerNull(): void
    {
        $this->assertNull(LspReleases::binaryEntry('rust', 'windows', 'x86_64'), 'no zip arm means no Windows pin, not a silent pass');
        $this->assertNull(LspReleases::binaryEntry('rust', 'linux', 'i686'));
        $this->assertNull(LspReleases::binaryEntry('php', 'linux', 'x86_64'), 'npm-arm languages never have binary entries');
    }

    // ── the allowlist gate ─────────────────────────────────────────────

    public function testTheGateAcceptsTheTwoShapesItWasBuiltFor(): void
    {
        $this->assertTrue(LspReleases::isAllowedUrl(
            'https://github.com/rust-lang/rust-analyzer/releases/download/2024-01-01/rust-analyzer-x86_64-unknown-linux-musl.gz',
        ), 'the gate is a shape law, not a copy of the pin table — a future re-pin must pass it unchanged');
        $this->assertTrue(LspReleases::isAllowedUrl(
            'https://objects.githubusercontent.com/github-production-release-asset-2e65be/123456?key=abc&filename=rust-analyzer-aarch64-apple-darwin.gz',
            "GitHub's own redirect bucket must pass, or every real download dies at the hop",
        ));
    }

    public function testTheGateRefusesEveryLookalikeShape(): void
    {
        $good = 'https://github.com/rust-lang/rust-analyzer/releases/download/2026-10-26/rust-analyzer-x86_64-unknown-linux-gnu.gz';
        $refusals = [
            'plain http' => str_replace('https://', 'http://', $good),
            'suffix-extended host' => str_replace('github.com/', 'github.com.evil.example/', $good),
            'userinfo' => str_replace('https://', 'https://x@', $good),
            'explicit port' => str_replace('github.com/', 'github.com:8443/', $good),
            'other host entirely' => str_replace('https://github.com/', 'https://evil.example/', $good),
            'wrong repository' => str_replace('/rust-lang/rust-analyzer/', '/evil/repo/', $good),
            'bad tag grammar' => str_replace('/2026-10-26/', '/../', $good),
            'non-gz asset' => str_replace('-unknown-linux-gnu.gz', '-unknown-linux-gnu.gz.evil.sh', $good),
            'trailing path segments' => $good . '/../../x',
            'objects bucket without filename' => 'https://objects.githubusercontent.com/github-production-release-asset/123?key=abc',
            'objects bucket wrong filename' => 'https://objects.githubusercontent.com/github-production-release-asset/123?filename=evil.sh',
            'objects wrong bucket' => 'https://objects.githubusercontent.com/somewhere-else/123?filename=rust-analyzer-x86_64-unknown-linux-gnu.gz',
        ];
        foreach ($refusals as $why => $url) {
            $this->assertFalse(LspReleases::isAllowedUrl($url), $why);
        }
    }

    // ── the E646-shaped client law ─────────────────────────────────────

    public function testDownloadOptionsCarryCeilingsAndNoTotalTimeout(): void
    {
        $options = LspInstaller::downloadOptions('/tmp/sc-lsp-sink-' . getmypid());

        $this->assertSame(LspInstaller::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        $this->assertSame(LspInstaller::READ_TIMEOUT_SECONDS, $options['read_timeout']);
        $this->assertArrayNotHasKey('timeout', $options, 'E646: a blanket total-request ceiling is exactly what must NOT appear');
        $this->assertFalse($options['http_errors']);
        $this->assertSame(LspInstaller::USER_AGENT, $options['headers']['User-Agent'], 'the wire identity is the lsp installer, never the phar updater');
        $this->assertSame($options['connect_timeout'], LspInstaller::clientOptions()['connect_timeout']);
        $this->assertArrayNotHasKey('timeout', LspInstaller::clientOptions());
    }

    public function testSizeAndRedirectGuardsRefuseBothWays(): void
    {
        $cap = LspInstaller::MAX_DOWNLOAD_BYTES;
        $progress = LspInstaller::progressGuard($cap);

        $this->expectGuardRefusal($progress, 0, $cap + 1, 'streamed bytes past the ceiling');
        $this->expectGuardRefusal($progress, $cap + 1, 0, 'a claimed Content-Length past the ceiling');
        $progress(1024, 512);
        $progress(-1, $cap);

        $redirect = LspInstaller::redirectGuard();
        $redirect(new Request('GET', 'https://objects.githubusercontent.com/github-production-release-asset/1?filename=rust-analyzer-x86_64-unknown-linux-gnu.gz'));
        $this->expectGuardRefusal($redirect, new Request('GET', 'https://evil.example/rust-analyzer-x86_64-unknown-linux-gnu.gz'));
        $this->expectGuardRefusal($redirect, new Request('GET', 'http://github.com/rust-lang/rust-analyzer/releases/download/2026-10-26/rust-analyzer-x86_64-unknown-linux-gnu.gz'));
    }

    // ── the binary arm, end to end against a fake wire ─────────────────

    public function testVerifiedBytesBecomeAnExecutableInBinAndNothingElse(): void
    {
        $payload = "#!/bin/sh\necho installed-server\n";
        $asset = gzencode($payload);
        $result = $this->runBinaryArm($this->fixtureEntry(hash('sha256', $asset)), $asset, 200);

        $this->assertTrue($result['ok'], $result['message']);
        $target = $this->root . '/bin/rust-analyzer';
        $this->assertSame($target, $result['path']);
        $this->assertSame($payload, file_get_contents($target));
        $this->assertTrue(is_executable($target));
        $this->assertSame(['rust-analyzer'], $this->listingOf($this->root . '/bin'), 'no temp or staged residue may survive a success');
        $this->assertSame($target, LspInstaller::installedBinaryPath('rust-analyzer', $this->root));
    }

    public function testMismatchedBytesRefuseBeforeAnythingLands(): void
    {
        $result = $this->runBinaryArm($this->fixtureEntry(str_repeat('0', 64)), gzencode('whatever the server sent'), 200);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('checksum mismatch', $result['message']);
        $this->assertSame([], $this->listingOf($this->root . '/bin'), 'a refused download leaves no binary and no temp');
        $this->assertNull(LspInstaller::installedBinaryPath('rust-analyzer', $this->root));
    }

    public function testTheChmodFailureBranchDiscardsTheStagedFileLikeItsRenameSibling(): void
    {
        // NIT-1: a chmod failure cannot be reached behaviorally in a
        // hermetic test (it answers false only on a read-only fs or an
        // immutable bit, neither of which a temp dir under the sandbox
        // offers), so the branch is pinned structurally — the same
        // source-slice genre the launcher precedence pin uses. The
        // leave-nothing law the listing asserts police elsewhere goes
        // blind here unless the unlink's shape is nailed: it must run
        // before the return, as in the rename-failure sibling below.
        $source = (string) file_get_contents(__DIR__ . '/../../src/LSP/LspInstaller.php');

        foreach (['if (@chmod($staged' => 'could not be made executable', 'if (!@rename($staged, $target))' => 'could not be moved into place'] as $anchor => $message) {
            $start = strpos($source, $anchor);
            $this->assertIsInt($start, $anchor . ' must stay as written in installBinary()');
            $branch = substr($source, $start, (int) strpos(substr($source, $start), '}'));

            $this->assertStringContainsString($message, $branch);
            $unlink = strpos($branch, '@unlink($staged)');
            $this->assertIsInt($unlink, $anchor . ' must discard the staged copy before refusing — a leaked .dl-*.out is found by installedBinaryPath() only if it also got chmod 755, but it must never survive at all');
            $this->assertLessThan((int) strpos($branch, 'return self::failure'), $unlink, 'the unlink must run before the return in ' . $anchor);
        }
    }

    public function testHttpErrorsAndTransportFailuresRefuseWithoutResidue(): void
    {
        $result = $this->runBinaryArm($this->fixtureEntry(str_repeat('0', 64)), '', 404);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HTTP 404', $result['message']);

        $client = $this->releaseClient(static fn (RequestInterface $request, array $options): PromiseInterface => new RejectedPromise(
            new ConnectException('connection refused', $request),
        ));
        $refused = $this->invokeInstallBinary(LspReleases::binaryEntry('rust', 'linux', 'x86_64'), $client);
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('download failed', $refused['message']);
        $this->assertSame([], $this->listingOf($this->root . '/bin'));
    }

    public function testTheGuardsAreWiredIntoTheActualRequest(): void
    {
        $entry = $this->fixtureEntry(str_repeat('1', 64));
        $handler = static function (RequestInterface $request, array $options): PromiseInterface {
            if (($options['on-redirect'] ?? null) !== null) {
                try {
                    ($options['on-redirect'])(new Request('GET', 'https://evil.example/x'));
                } catch (\RuntimeException $refused) {
                    return new RejectedPromise($refused);
                }
            }

            return new FulfilledPromise(new Response(200));
        };
        $result = $this->runBinaryArmWith($handler, $entry);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('left the pinned release allowlist', $result['message']);

        $progressHandler = static function (RequestInterface $request, array $options): PromiseInterface {
            if (($options['progress'] ?? null) !== null) {
                try {
                    ($options['progress'])(0, LspInstaller::MAX_DOWNLOAD_BYTES + 1);
                } catch (\RuntimeException $refused) {
                    return new RejectedPromise($refused);
                }
            }

            return new FulfilledPromise(new Response(200));
        };
        $overCap = $this->runBinaryArmWith($progressHandler, $entry);
        $this->assertFalse($overCap['ok']);
        $this->assertStringContainsString('exceeded the', $overCap['message']);
    }

    public function testAManifestForgedOutsideTheTableStillDiesAtTheGate(): void
    {
        $forged = $this->fixtureEntry(str_repeat('2', 64));
        $forged['url'] = 'https://evil.example/rust-analyzer-x86_64-unknown-linux-gnu.gz';
        $handed = 0;
        $handler = static function (RequestInterface $request, array $options) use (&$handed): PromiseInterface {
            $handed++;

            return new FulfilledPromise(new Response(200));
        };
        $result = $this->runBinaryArmWith($handler, $forged);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('outside the allowlist', $result['message']);
        $this->assertSame(0, $handed, 'the URL re-check must fire before a single request goes out');
    }

    public function testThePublicInstallLegRefusesWrongServedBytesOnRealPins(): void
    {
        $client = $this->releaseClient(new MockHandler([new Response(200, [], gzencode('not the published asset'))]));
        $result = LspInstaller::install('rust', $client, $this->root, os: 'linux', arch: 'x86_64');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('checksum mismatch for rust-analyzer-x86_64-unknown-linux-gnu.gz', $result['message']);
    }

    // ── the npm arm ────────────────────────────────────────────────────

    public function testTheNpmCommandLineIsBuiltLoudAndShellSafe(): void
    {
        $command = LspInstaller::npmCommand('/tmp/x lsp', 'intelephense', '1.18.5');

        $this->assertStringContainsString(' --ignore-scripts', $command);
        $this->assertStringContainsString(' --no-save', $command);
        $this->assertStringContainsString('--prefix ' . escapeshellarg('/tmp/x lsp'), $command);
        $this->assertStringContainsString('--registry ' . escapeshellarg('https://registry.npmjs.org'), $command);
        $this->assertStringEndsWith(escapeshellarg('intelephense@1.18.5'), $command, 'the pinned pair must ride as ONE shell word');

        $hostile = LspInstaller::npmCommand('/tmp/x', "a'; rm -rf ~; '", '1.0');
        $this->assertStringEndsWith(escapeshellarg("a'; rm -rf ~; '@1.0"), $hostile, 'even a forged package name can only ever be one quoted word');
    }

    public function testTheNpmArmShipsThePinnedCommandAndVerifiesTheBin(): void
    {
        $seen = null;
        $run = function (string $command) use (&$seen): array {
            $seen = $command;
            $this->plantServerAt('node_modules/.bin/intelephense');

            return ['exitCode' => 0, 'stderr' => '', 'timedOut' => false];
        };
        $result = LspInstaller::install('php', null, $this->root, $run);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertStringContainsString('--ignore-scripts', (string) $seen);
        $this->assertStringContainsString(escapeshellarg('intelephense@1.18.5'), (string) $seen);
        $this->assertSame($this->root . '/node_modules/.bin/intelephense', $result['path']);
        $this->assertStringContainsString('intelephense 1.18.5', $result['message']);
    }

    public function testTheNpmArmRefusalsNameExitTimeAndMissingBinaries(): void
    {
        $failed = LspInstaller::install('python', null, $this->root, static fn (string $command): array => [
            'exitCode' => 3,
            'stderr' => 'npm ERR! could not reach registry',
            'timedOut' => false,
        ]);
        $this->assertFalse($failed['ok']);
        $this->assertStringContainsString('exit 3', $failed['message']);
        $this->assertStringContainsString('could not reach registry', $failed['message']);
        $this->assertStringContainsString('pyright', $failed['message']);

        $timeout = LspInstaller::install('python', null, $this->root, static fn (string $command): array => [
            'exitCode' => 0,
            'stderr' => '',
            'timedOut' => true,
        ]);
        $this->assertFalse($timeout['ok']);
        $this->assertStringContainsString('300 second bound', $timeout['message']);

        $vanished = LspInstaller::install('typescript', null, $this->root, static fn (string $command): array => [
            'exitCode' => 0,
            'stderr' => '',
            'timedOut' => false,
        ]);
        $this->assertFalse($vanished['ok']);
        $this->assertStringContainsString('typescript-language-server did not appear', $vanished['message']);
    }

    public function testAnUnprivateInstallRootRefusesBeforeAnyChildOrFetch(): void
    {
        $hostile = $this->root . '/public-tree';
        mkdir($hostile, 0o755, true);
        $touched = false;
        $result = LspInstaller::install('php', null, $hostile, static function (string $command) use (&$touched): array {
            $touched = true;

            return ['exitCode' => 0, 'stderr' => '', 'timedOut' => false];
        });

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not 0700', $result['message']);
        $this->assertFalse($touched);
    }

    public function testUnserveableLanguagesRefuseNamingTheVerdict(): void
    {
        $result = LspInstaller::install('go', null, $this->root, os: 'linux', arch: 'x86_64');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no pinned go release for linux/x86_64', $result['message']);
        $this->assertStringContainsString('go toolchain', $result['message'], 'the refusal says WHY go has no arm here');

        $windows = LspInstaller::install('rust', null, $this->root, os: 'windows', arch: 'x86_64');
        $this->assertFalse($windows['ok']);
        $this->assertStringContainsString('no pinned rust release for windows/x86_64', $windows['message']);
    }

    // ── the shared probe ───────────────────────────────────────────────

    public function testTheProbeWalksBinBeforeNodeModulesAndRefusesPathShapes(): void
    {
        $this->assertNull(LspInstaller::installedBinaryPath('', $this->root));
        $this->assertNull(LspInstaller::installedBinaryPath('sub/dir', $this->root), 'a slash would escape the tree');
        $this->assertNull(LspInstaller::installedBinaryPath('gopls', $this->root));

        $lazy = $this->plantServerAt('node_modules/.bin/pyright-langserver', executable: false);
        $this->assertNull(LspInstaller::installedBinaryPath('pyright-langserver', $this->root), 'a non-executable file is not an installed server');
        chmod($lazy, 0o755);
        $lazyPath = $this->root . '/node_modules/.bin/pyright-langserver';
        $this->assertSame($lazyPath, LspInstaller::installedBinaryPath('pyright-langserver', $this->root));

        $binSide = $this->plantServerAt('bin/pyright-langserver');
        $this->assertSame($binSide, LspInstaller::installedBinaryPath('pyright-langserver', $this->root), 'bin/ wins when both arms carry the name');

        $this->assertNull(LspInstaller::installedBinaryPath('pyright-langserver', $this->root . '/nonexistent'));
    }

    // ── harness ────────────────────────────────────────────────────────

    /**
     * @return array{url: string, asset: string, sha256: string, binary: string}
     */
    private function fixtureEntry(string $sha256): array
    {
        $entry = LspReleases::binaryEntry('rust', 'linux', 'x86_64');
        $this->assertNotNull($entry);
        $entry['sha256'] = $sha256;

        return $entry;
    }

    /**
     * @param array{url: string, asset: string, sha256: string, binary: string} $entry
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    private function runBinaryArm(array $entry, string $servedBody, int $status): array
    {
        return $this->runBinaryArmWith(
            static function (RequestInterface $request, array $options) use ($servedBody, $status): PromiseInterface {
                if (is_string($options['sink'] ?? null) && $status < 400) {
                    file_put_contents($options['sink'], $servedBody);
                }

                return new FulfilledPromise(new Response($status));
            },
            $entry,
        );
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): PromiseInterface $handler
     * @param array{url: string, asset: string, sha256: string, binary: string}   $entry
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    private function runBinaryArmWith(callable $handler, array $entry): array
    {
        return $this->invokeInstallBinary($entry, $this->releaseClient($handler));
    }

    /**
     * @param callable(RequestInterface, array<string, mixed>): PromiseInterface $handler
     */
    private function releaseClient(callable|MockHandler $handler): Client
    {
        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    /**
     * The download half of the installer is exercised through the private
     * arm on purpose: install() would force a real manifest entry, whose
     * pinned hashes a test can never satisfy without shipping a fake
     * network-fetched asset — reflection pins the SAME code the CLI runs.
     *
     * @param array{url: string, asset: string, sha256: string, binary: string} $entry
     *
     * @return array{ok: bool, message: string, path: ?string}
     */
    private function invokeInstallBinary(array $entry, Client $client): array
    {
        $method = new ReflectionMethod(LspInstaller::class, 'installBinary');
        $method->setAccessible(true);
        /** @var array{ok: bool, message: string, path: ?string} $result */
        $result = $method->invoke(null, $entry, $this->root, $client);

        return $result;
    }

    private function plantServerAt(string $relative, bool $executable = true): string
    {
        $path = $this->root . '/' . $relative;
        @mkdir(\dirname($path), 0o700, true);
        file_put_contents($path, "#!/bin/sh\n");
        chmod($path, $executable ? 0o755 : 0o644);

        return $path;
    }

    /** @return list<string> */
    private function listingOf(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(scandir($dir) ?: [], static fn (string $name): bool => $name !== '.' && $name !== '..'));
    }

    /**
     * @param \Closure(int|object ...$args): void $guard
     */
    private function expectGuardRefusal(\Closure $guard, mixed ...$args): void
    {
        try {
            $guard(...$args);
        } catch (\RuntimeException $refused) {
            $this->assertStringContainsString('lsp install', $refused->getMessage());

            return;
        }
        $this->fail('the guard accepted input it must refuse');
    }

    private function pruneInstallerTrees(): void
    {
        $this->removeEverythingUnder($this->root);
    }

    /** Deletes this test tree only — the root is a private temp dir this file minted. */
    private function removeEverythingUnder(string $dir): void
    {
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $child = $dir . '/' . $name;
            if (is_dir($child) && !is_link($child)) {
                $this->removeEverythingUnder($child);
                @rmdir($child);
            } else {
                @unlink($child);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\SelfUpdate;
use SugarCraft\Code\Tests\Support\RunsWallClockBoundedChildTrait;

/**
 * C-SES-6.c: the `install.sh` bootstrapper in `tools/` against file://
 * release fixtures.
 *
 * The script performs the same ladder the in-product `self-update` runs —
 * resolve the release, download the phar plus its sidecar plus a signature,
 * verify the signature against the PINNED key, compare the digest, install
 * mode 0755 — so these tests exercise it end to end with real `sh`, real
 * `curl` (reading `file://` URLs — zero sockets, zero network) and real
 * sodium signatures, over a copy of the script whose pinned key line has
 * been replaced by a fixture keypair. The shipped key literal itself is
 * pinned against {@see SelfUpdate::PUBLIC_KEY_BASE64}, so the copy trick
 * never stops meaning what ships.
 *
 * What is NOT tested here: the GitHub-API pagination leg needs a live API
 * (it mirrors {@see SelfUpdate::selectRelease}, pinned in its own suite),
 * and `--channel dev` against the real `candy-code/dev` tag is likewise a
 * live shape — the dev leg is pinned against fixtures instead.
 */
final class InstallScriptTest extends TestCase
{
    use RunsWallClockBoundedChildTrait;

    private const STABLE_TAG = 'candy-code/v9.8.7';

    private string $scriptPath;

    private string $root;

    private string $fixtureRoot;

    /** @var array{public: string, secret: string} */
    private array $fixtureKeyPair;

    protected function setUp(): void
    {
        if ($this->runBoundedSh(['sh', '-c', 'command -v curl'])[0] !== 0) {
            $this->markTestSkipped('install.sh needs curl on PATH, which this host does not provide');
        }
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('the fixture signatures need the sodium extension');
        }

        $this->scriptPath = \dirname(__DIR__, 2) . '/tools/install.sh';
        $this->assertIsReadable($this->scriptPath, 'the lane shipped tools/install.sh');

        $pair = \sodium_crypto_sign_keypair();
        $this->fixtureKeyPair = [
            'public' => \base64_encode(\sodium_crypto_sign_publickey($pair)),
            'secret' => \sodium_crypto_sign_secretkey($pair),
        ];

        $this->root = (string) \realpath(\sys_get_temp_dir()) . '/cc_ses6c_' . \bin2hex(\random_bytes(6));
        \mkdir($this->root);
        $this->fixtureRoot = $this->root . '/fixture';
        \mkdir($this->fixtureRoot);
    }

    protected function tearDown(): void
    {
        $this->purgeFixtureTree($this->root);
    }

    public function testTheScriptParsesAsPosixShell(): void
    {
        [$status, $output] = $this->runBoundedSh(['sh', '-n', $this->scriptPath]);

        $this->assertSame(0, $status, 'sh -n said: ' . $output);
    }

    public function testThePinnedKeyLineEqualsTheShippedProductConstant(): void
    {
        $body = (string) \file_get_contents($this->scriptPath);

        $this->assertSame(
            1,
            \preg_match('/^PINNED_PUBLIC_KEY="([A-Za-z0-9+\/=]+)"$/m', $body, $m),
            'install.sh must carry exactly one pinned-key assignment in the shape the tests grep'
        );
        $this->assertSame(
            SelfUpdate::PUBLIC_KEY_BASE64,
            $m[1],
            'install.sh and SelfUpdate are one trust boundary: the pinned Ed25519 key must be '
                . 'the same literal in both, flipped in the same commit (§1.8 in-step law)'
        );
    }

    public function testTheScriptCarriesNoEnvironmentVariableReads(): void
    {
        // The brief settled the interface on flags-only ("NO env vars") so
        // EnvRosterDriftTest stays untouched by construction. This pin makes
        // the construction visible: getenv() never appears, and no
        // CANDY_CODE_ or SUGARCRUSH_ family name does either.
        $body = (string) \file_get_contents($this->scriptPath);

        $this->assertStringNotContainsString('getenv(', $body);
        $this->assertStringNotContainsString('CANDY_CODE_', $body);
        $this->assertStringNotContainsString('SUGARCRUSH_', $body);
    }

    public function testTheHappyPathInstallsTheSignedPharFromAFixtureRelease(): void
    {
        $pharBytes = 'CANDY-PHAR-PAYLOAD-' . \uniqid('', true);
        $this->mintFixtureRelease(self::STABLE_TAG, $pharBytes, $this->sidecarFor($pharBytes), true);
        $binDir = $this->root . '/bin';

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $binDir,
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('install.sh: selected ' . self::STABLE_TAG, $output);
        $this->assertStringContainsString(
            'signature (candy-code.phar.sha256.sig) and sha256 verified',
            $output
        );

        $dest = $binDir . '/candy-code';
        $this->assertFileExists($dest);
        $this->assertSame($pharBytes, (string) \file_get_contents($dest), 'the installed bytes are the signed bytes');
        $this->assertSame(0o755, \fileperms($dest) & 0o777, 'the brief pins mode 0755 on the installed file');
        $this->assertStringContainsString('installed candy-code v9.8.7 to ' . $dest, $output);
    }

    public function testASidecarTamperedAfterSigningIsRefusedAtTheSignatureLeg(): void
    {
        $pharBytes = 'CANDY-PHAR-HONEST-' . \uniqid('', true);
        $releaseDir = $this->mintFixtureRelease(self::STABLE_TAG, $pharBytes, $this->sidecarFor($pharBytes), true);

        // The MITM's move: rewrite the sidecar to bless an attacker digest.
        // The signature covers the ORIGINAL sidecar bytes, so the pinned-key
        // leg must refuse before the digest is ever consulted.
        \file_put_contents(
            $releaseDir . '/candy-code.phar.sha256',
            \str_repeat('f', 64) . " candy-code.phar\n"
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('does not verify the sidecar against the pinned key', $output);
        $this->assertFileDoesNotExist($this->root . '/bin/candy-code');
    }

    public function testADigestMismatchBehindAnIntactSignatureIsRefused(): void
    {
        // The other forged release: signature and sidecar are consistent (the
        // pinned key vouches for them), but the sidecar promises a digest the
        // actual phar does not hash to. The digest leg, running behind an
        // intact signature, has to be the one that says no.
        $pharBytes = 'CANDY-PHAR-VICTIM-' . \uniqid('', true);
        $this->mintFixtureRelease(
            self::STABLE_TAG,
            $pharBytes,
            \hash('sha256', 'some-other-bytes') . " candy-code.phar\n",
            true
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('failed its sha256 check', $output);
        $this->assertFileDoesNotExist($this->root . '/bin/candy-code');
    }

    public function testAReleaseWithoutAnySignatureAssetIsRefused(): void
    {
        $pharBytes = 'CANDY-PHAR-UNSIGNED-' . \uniqid('', true);
        $this->mintFixtureRelease(self::STABLE_TAG, $pharBytes, $this->sidecarFor($pharBytes), false);

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('no signature asset', $output);
        $this->assertStringContainsString('refusing to install an unverified phar', $output);
        $this->assertFileDoesNotExist($this->root . '/bin/candy-code');
    }

    public function testTheLegacyMinisigLegStillInstallsAndAnnouncesItself(): void
    {
        // SelfUpdate accepts a phar-wide .minisig for releases that predate
        // the sidecar signature; the script mirrors the fallback so the same
        // releases install identically. The note line keeps the asymmetry
        // visible instead of silently preferring one asset.
        $pharBytes = 'CANDY-PHAR-LEGACY-' . \uniqid('', true);
        $this->mintFixtureRelease(
            self::STABLE_TAG,
            $pharBytes,
            $this->sidecarFor($pharBytes),
            false,
            $pharBytes
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('deprecated signature asset', $output);
        $this->assertStringContainsString('signature (candy-code.phar.minisig) and sha256 verified', $output);
        $this->assertSame($pharBytes, (string) \file_get_contents($this->root . '/bin/candy-code'));
    }

    public function testASidecarNamingAnotherFileIsRefused(): void
    {
        // Same rule as SelfUpdate's sidecarName guard: a sidecar whose
        // optional second field names something other than the phar asset is
        // refused even though its digest would happen to match this payload.
        $pharBytes = 'CANDY-PHAR-NAMING-' . \uniqid('', true);
        $this->mintFixtureRelease(
            self::STABLE_TAG,
            $pharBytes,
            $this->sidecarDigest($pharBytes) . " candy-code-evil.phar\n",
            true
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertNotSame(0, $status);
        $this->assertStringContainsString("names 'candy-code-evil.phar'", $output);
        $this->assertFileDoesNotExist($this->root . '/bin/candy-code');
    }

    public function testTheSelectorPrefersTheHighestComparableVersionAndSkipsPrereleases(): void
    {
        $winnerBytes = 'CANDY-PHAR-WINNER-' . \uniqid('', true);
        $winner = $this->fixtureReleaseEntry('candy-code/v9.8.7', $winnerBytes, $this->sidecarFor($winnerBytes), true);

        $olderBytes = 'CANDY-PHAR-OLDER-' . \uniqid('', true);
        $older = $this->fixtureReleaseEntry('candy-code/0.9.0', $olderBytes, $this->sidecarFor($olderBytes), true);

        $prereleaseBytes = 'CANDY-PHAR-PRERELEASE-' . \uniqid('', true);
        $prerelease = $this->fixtureReleaseEntry(
            'candy-code/v10.0.0',
            $prereleaseBytes,
            $this->sidecarFor($prereleaseBytes),
            true
        );
        $prerelease['prerelease'] = true; // SelfUpdate's stable rule: skip it, higher or not

        $malformed = $this->fixtureReleaseEntry('candy-code/not-a-version', 'x', 'y', true);
        $otherProject = $this->fixtureReleaseEntry('candy-top/v99.0.0', 'x', 'y', true);

        \file_put_contents(
            $this->fixtureRoot . '/releases.json',
            \json_encode([$older, $prerelease, $winner, $malformed, $otherProject])
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('selected ' . self::STABLE_TAG, $output);
        $this->assertSame($winnerBytes, (string) \file_get_contents($this->root . '/bin/candy-code'));
    }

    public function testTheDevChannelSelectsTheExactDevTagEvenWhenStableIsHigher(): void
    {
        $devBytes = 'CANDY-PHAR-DEV-' . \uniqid('', true);
        $dev = $this->fixtureReleaseEntry(SelfUpdate::DEV_TAG, $devBytes, $this->sidecarFor($devBytes), true);
        $dev['prerelease'] = true; // the rolling dev release IS a prerelease

        $stableBytes = 'CANDY-PHAR-STABLE-' . \uniqid('', true);
        $stable = $this->fixtureReleaseEntry('candy-code/v99.0.0', $stableBytes, $this->sidecarFor($stableBytes), true);

        \file_put_contents(
            $this->fixtureRoot . '/releases.json',
            \json_encode([$stable, $dev])
        );

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
            '--channel', 'dev',
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('selected ' . SelfUpdate::DEV_TAG, $output);
        $this->assertSame($devBytes, (string) \file_get_contents($this->root . '/bin/candy-code'));

        // The other polarity in the same fixture: the default stable channel
        // picks the higher stable release and skips the exact-tag dev one.
        $this->purgeFixtureTree($this->root . '/bin');
        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $this->root . '/bin',
        ]);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('selected candy-code/v99.0.0', $output);
        $this->assertSame($stableBytes, (string) \file_get_contents($this->root . '/bin/candy-code'));
    }

    public function testUrlsAndPathsCarryingSpacesSurviveTheQuoting(): void
    {
        // A directory name with a space is the classic quoting hazard: the
        // URL needs percent-encoding and every shell word needs quoting, and
        // neither may be quietly dropped.
        $spaced = $this->root . '/weird dir';
        \mkdir($spaced);
        $this->fixtureRoot = $spaced . '/fixture';
        \mkdir($this->fixtureRoot);
        $binDir = $spaced . '/bin out';

        $pharBytes = 'CANDY-PHAR-SPACED-' . \uniqid('', true);
        $this->mintFixtureRelease(self::STABLE_TAG, $pharBytes, $this->sidecarFor($pharBytes), true);

        [$status, $output] = $this->runSwappedInstaller([
            '--base-url', $this->fixtureFileUrl($this->fixtureRoot),
            '--dir', $binDir,
        ]);

        $this->assertSame(0, $status, $output);
        $this->assertSame($pharBytes, (string) \file_get_contents($binDir . '/candy-code'));
    }

    public function testHelpExitsZeroAndBadFlagsExitNonZero(): void
    {
        [$status, $output] = $this->runBoundedSh(['sh', $this->scriptPath, '--help']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Usage: install.sh', $output);

        [$status, $output] = $this->runBoundedSh(['sh', $this->scriptPath, '--bogus-flag']);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString("unknown option '--bogus-flag'", $output);

        [$status, $output] = $this->runBoundedSh(['sh', $this->scriptPath, '--channel', 'nightly']);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('unknown --channel', $output);

        [$status, $output] = $this->runBoundedSh(['sh', $this->scriptPath, '--dir']);
        $this->assertNotSame(0, $status);
        $this->assertStringContainsString('--dir requires a value', $output);
    }

    // ---------------------------------------------------------------- legs ---

    /**
     * Write one release into its own fixture directory and describe every
     * asset with a file:// URL in releases.json. Returns the release's asset
     * directory so a test can tamper with one leg afterwards.
     */
    private function mintFixtureRelease(string $tag, string $pharBytes, string $sidecarBody, bool $sigOverSidecar, ?string $minisigOverPharBytes = null): string
    {
        $release = $this->fixtureReleaseEntry($tag, $pharBytes, $sidecarBody, $sigOverSidecar, $minisigOverPharBytes);
        \file_put_contents($this->fixtureRoot . '/releases.json', \json_encode([$release]));

        return $this->fixtureRoot . '/' . $tag;
    }

    /**
     * One release in the GitHub-API shape. With $sigOverSidecar the sidecar's
     * EXACT file bytes are signed with the fixture keypair; with
     * $minisigOverPharBytes the raw phar bytes are signed under the legacy
     * asset name instead. Assets live under fixture/<tag>/ — mirroring the
     * real download layout, and keeping two releases in one fixture from
     * overwriting each other's legs.
     *
     * @return array<string, mixed>
     */
    private function fixtureReleaseEntry(string $tag, string $pharBytes, string $sidecarBody, bool $sigOverSidecar, ?string $minisigOverPharBytes = null): array
    {
        $dir = $this->fixtureRoot . '/' . $tag;
        \mkdir($dir, 0o777, true);

        $assets = [
            $this->writeFixtureAsset($dir, 'candy-code.phar', $pharBytes),
            $this->writeFixtureAsset($dir, 'candy-code.phar.sha256', $sidecarBody),
        ];
        if ($sigOverSidecar) {
            $assets[] = $this->writeFixtureAsset($dir, 'candy-code.phar.sha256.sig', $this->signWithFixtureKey($sidecarBody));
        }
        if ($minisigOverPharBytes !== null) {
            $assets[] = $this->writeFixtureAsset($dir, 'candy-code.phar.minisig', $this->signWithFixtureKey($minisigOverPharBytes));
        }

        return [
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => false,
            'assets' => $assets,
        ];
    }

    /** @return array{name: string, browser_download_url: string} */
    private function writeFixtureAsset(string $dir, string $name, string $bytes): array
    {
        \file_put_contents($dir . '/' . $name, $bytes);

        return ['name' => $name, 'browser_download_url' => $this->fixtureFileUrl($dir . '/' . $name)];
    }

    private function signWithFixtureKey(string $payload): string
    {
        return \base64_encode(\sodium_crypto_sign_detached($payload, $this->fixtureKeyPair['secret']));
    }

    private function sidecarDigest(string $pharBytes): string
    {
        return \hash('sha256', $pharBytes);
    }

    private function sidecarFor(string $pharBytes): string
    {
        // The shipped format's canonical line: digest, space, asset name.
        return $this->sidecarDigest($pharBytes) . " candy-code.phar\n";
    }

    private function fixtureFileUrl(string $path): string
    {
        // curl rejects raw spaces inside file:// URLs; percent-encoding is
        // what any real release page carries for such names anyway.
        return 'file://' . \str_replace(' ', '%20', $path);
    }

    /**
     * Run tools/install.sh with the PINNED key line swapped to the fixture
     * keypair. Only the copy is ever modified — the shipped script's key
     * literal is pinned separately, byte-for-byte, above.
     *
     * @param list<string> $flags
     *
     * @return array{0: int, 1: string}
     */
    private function runSwappedInstaller(array $flags): array
    {
        $needle = 'PINNED_PUBLIC_KEY="' . SelfUpdate::PUBLIC_KEY_BASE64 . '"';
        $body = (string) \file_get_contents($this->scriptPath);
        $this->assertStringContainsString($needle, $body);
        $swapped = \str_replace($needle, 'PINNED_PUBLIC_KEY="' . $this->fixtureKeyPair['public'] . '"', $body);
        $this->assertStringContainsString($this->fixtureKeyPair['public'], $swapped);

        $testScript = $this->root . '/install-under-test.sh';
        \file_put_contents($testScript, $swapped);

        return $this->runBoundedSh(['sh', $testScript, ...$flags]);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{0: int, 1: string}
     */
    private function runBoundedSh(array $argv): array
    {
        $command = \implode(' ', \array_map('escapeshellarg', $argv));
        [$status, $lines] = $this->runWallClockBoundedChild('', $command . ' 2>&1');

        return [$status, \implode("\n", $lines)];
    }

    private function purgeFixtureTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
        $items = \iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ), false);
        foreach ($items as $item) {
            \is_dir((string) $item) ? \rmdir((string) $item) : \unlink((string) $item);
        }
        \rmdir($path);
    }
}

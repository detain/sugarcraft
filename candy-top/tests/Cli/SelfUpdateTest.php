<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Cli;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Top\Cli\Help;
use SugarCraft\Top\Cli\SelfUpdate;
use SugarCraft\Top\Support\Version;
use SugarCraft\Top\Tests\Support\MintsStampedPharTrait;

/**
 * DST-3: `candy-top self-update` end to end against a fake release server.
 *
 * Port of candy-code/tests/Cli/SelfUpdateTest.php — the release-safety laws
 * are the reason this suite exists, so the pins travel with them. Two shapes
 * differ by candy-top's local conventions: the verb's flags arrive as the
 * plain array the binary slices out of argv (candy-code runs them through its
 * ArgvParser; the dispatch-roster pin below replaces the parser-roster pin),
 * and the exit constants live on SelfUpdate itself (candy-code borrows
 * NonInteractive's).
 *
 * The fake server is a Guzzle handler callable mounted into a REAL client's
 * middleware stack: routes are the fixture directory's own files, served over
 * canonical http URLs, with the handler's sink/ response-body behaviour
 * standing in for the transport. Zero sockets, zero child processes, zero
 * real network — while SelfUpdate still dials through an untouched
 * Client::request path (Guzzle's stock StreamHandler is not an option: it
 * refuses the file:// scheme outright). The phar under swap is a stub
 * archive; the swap machinery treats bytes, and \Phar::running is replaced by
 * the $pharPath seam. The sole exception is the DST-1 stamp pin: reading a
 * real minted archive needs a child process, provided by the shared
 * {@see MintsStampedPharTrait} helper, and never a socket either.
 */
final class SelfUpdateTest extends TestCase
{
    use MintsStampedPharTrait;

    private const SERVER_ORIGIN = 'http://fake-candy-top-server.invalid';

    /**
     * TEST-ONLY Ed25519 keypair (DST-3 signature pins): copied verbatim from
     * the candy-code suite — generated once by `sodium_crypto_sign_keypair()`
     * on the build box and frozen here so the pins verify without signing at
     * runtime. A runner whose PHP was built without ext-sodium still exercises
     * every keyless and fail-closed polarity through the same constants.
     * Nothing real is signed: the key has no identity outside test files.
     */
    private const FIXTURE_PUBLIC_KEY_BASE64 = '3f6JBhTbQUb28TeT6ygMqnETA46TgDdVEp39HUC8eUg=';

    private const FIXTURE_VALID_SIGNATURE = 'bJUCdn/br90XhiaIUKmeLFmDylCIDo7NCW8AJmvPUk7MP8Qr7XF5sfGUhI2yeDYybBca6Fad4nCbqzSwKBXRCw==';

    /** The same keypair's signature over OTHER bytes: structurally valid, cryptographically forged. */
    private const FIXTURE_FORGED_SIGNATURE = 'mV4B/yaL1+gXurXu02mi4WBE5gfeTKNhdNlc3/xUpHUrycDIesPZXiN1/X8+gmmM2jD3DGNU/ZZlGxrPURPnAg==';

    private const SIGNED_PAYLOAD_BYTES = 'UPDATED-PHAR-BYTES';

    private string $dir;

    /** @var resource */
    private $errorStream;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/ctop-su-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($this->dir . '/srv', 0o700, true));
        self::assertTrue(\mkdir($this->dir . '/live', 0o700, true));
        // The writability gates come before any fetch, so every scenario
        // needs an installed phar standing in the live slot.
        self::assertNotFalse(\file_put_contents($this->dir . '/live/candy-top.phar', 'INSTALLED-PHAR-BYTES'));
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        $this->errorStream = $stream;
        SelfUpdate::useErrorStreamForTesting($this->errorStream);
    }

    protected function tearDown(): void
    {
        SelfUpdate::useErrorStreamForTesting(null);
        \fclose($this->errorStream);
        self::deleteTree($this->dir);
    }

    private static function deleteTree(string $path): void
    {
        if (!\is_dir($path)) {
            if (\is_file($path)) {
                @\unlink($path);
            }

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::deleteTree($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }

    /**
     * The fake release server: any GET under the origin is answered from
     * srv/<path>; unknown routes reject with the ConnectException a dead
     * dial would produce. The sink option is honoured because the production
     * download asks for one.
     */
    private function fakeServerClient(): Client
    {
        $srv = $this->dir . '/srv';
        $handler = static function (RequestInterface $request, array $options) use ($srv): PromiseInterface {
            $file = $srv . \rawurldecode($request->getUri()->getPath());
            if (!\is_file($file)) {
                return new RejectedPromise(new ConnectException(
                    'fake server has no route ' . $request->getUri()->getPath(),
                    $request,
                ));
            }
            $body = (string) \file_get_contents($file);
            if (isset($options['sink']) && \is_string($options['sink'])) {
                if (\file_put_contents($options['sink'], $body) === false) {
                    return new RejectedPromise(new ConnectException('fake sink write failed', $request));
                }

                return new FulfilledPromise(new Response(200, ['Content-Length' => (string) \strlen($body)]));
            }

            return new FulfilledPromise(new Response(200, ['Content-Length' => (string) \strlen($body)], Utils::streamFor($body)));
        };

        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    private function errorText(): string
    {
        \rewind($this->errorStream);

        return (string) \stream_get_contents($this->errorStream);
    }

    private function resetErrorStream(): void
    {
        \fclose($this->errorStream);
        $stream = \fopen('php://temp', 'r+');
        self::assertIsResource($stream);
        $this->errorStream = $stream;
        SelfUpdate::useErrorStreamForTesting($stream);
    }

    private function livePharPath(): string
    {
        return $this->dir . '/live/candy-top.phar';
    }

    /**
     * Stage one release on the fake server and return its JSON entry. The
     * sidecar defaults to the true digest of the staged phar in sha256sum's
     * own '<hex>  <name>' spelling.
     *
     * @return array<array-key, mixed>
     */
    private function stageRelease(string $tag, string $pharBytes, ?string $sidecarBody = null, bool $withSidecar = true, bool $draft = false, bool $prerelease = false, ?string $sigBody = null): array
    {
        $slug = \strtr($tag, ['/' => '-']);
        $assets = [[
            'name' => SelfUpdate::PHAR_ASSET,
            'browser_download_url' => self::SERVER_ORIGIN . '/' . $slug . '.phar',
        ]];
        self::assertNotFalse(\file_put_contents($this->dir . '/srv/' . $slug . '.phar', $pharBytes));
        if ($sigBody !== null) {
            \file_put_contents($this->dir . '/srv/' . $slug . '.minisig', $sigBody);
            $assets[] = [
                'name' => SelfUpdate::SIGNATURE_ASSET,
                'browser_download_url' => self::SERVER_ORIGIN . '/' . $slug . '.minisig',
            ];
        }
        if ($withSidecar) {
            $body = $sidecarBody ?? (\hash('sha256', $pharBytes) . '  ' . SelfUpdate::PHAR_ASSET . "\n");
            self::assertNotFalse(\file_put_contents($this->dir . '/srv/' . $slug . '.sha256', $body));
            $assets[] = [
                'name' => SelfUpdate::SIDECAR_ASSET,
                'browser_download_url' => self::SERVER_ORIGIN . '/' . $slug . '.sha256',
            ];
        }

        return [
            'tag_name' => $tag,
            'draft' => $draft,
            'prerelease' => $prerelease,
            'assets' => $assets,
        ];
    }

    /** @param list<array<array-key, mixed>> $entries */
    private function releasesUrl(array $entries): string
    {
        self::assertNotFalse(\file_put_contents($this->dir . '/srv/releases.json', \json_encode($entries, \JSON_THROW_ON_ERROR)));

        return self::SERVER_ORIGIN . '/releases.json';
    }

    /**
     * The binary's dispatch arm is the verb's roster: candy-code pins the
     * arm against its Subcommands class; candy-top parses argv inline in
     * bin/candy-top, so the pin reads that source directly. Losing the arm
     * would make every lang string and flag below unreachable.
     */
    public function testTheBinaryDispatchesTheVerbAndDocumentsItInHelp(): void
    {
        $bin = (string) \file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-top');
        self::assertStringContainsString(
            'if ($arg === \'self-update\') {',
            $bin,
            'the dispatch arm for the verb left the binary — the roster would lie',
        );
        self::assertStringContainsString('SelfUpdate::run(array_slice($args, $i + 1))', $bin);
        self::assertStringContainsString('candy-top self-update [--check] [--rollback] [--allow-unsigned]', $bin);
    }

    public function testAComposerInstallIsRefusedLoudlyAndNeverSilently(): void
    {
        // Production shape: no seams at all. The test process is not inside a
        // phar, so Phar::running() answers '' — the refusal must fire BEFORE
        // any client is built (a silent no-op here would exit 0).
        $exit = SelfUpdate::run([]);
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit);
        $text = $this->errorText();
        self::assertStringContainsString('Composer', $text);
        self::assertStringContainsString('composer update sugarcraft/candy-top', $text);
        self::assertStringContainsString('composer global update sugarcraft/candy-top', $text);
        self::assertSame(SelfUpdate::composerRefusal(), \trim($text), 'the refusal is the documented sentence verbatim');
    }

    public function testCheckDistinguishesAvailableFromUpToDateByExitCode(): void
    {
        $available = SelfUpdate::run(
            ['--check'],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $available, '--check must exit 1 when a newer release exists');
        self::assertStringContainsString('update available', $this->errorText());

        $this->resetErrorStream();

        $upToDate = SelfUpdate::run(
            ['--check'],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v0.1.0', 'NEW')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_OK, $upToDate, 'an up-to-date --check is success, not failure');
        self::assertStringContainsString('up to date', $this->errorText());
    }

    public function testCheckOnAnUnorderableInstallSaysSo(): void
    {
        $exit = SelfUpdate::run(
            ['--check'],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'dev-master',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit);
        self::assertStringContainsString('cannot compare', $this->errorText());
    }

    public function testGoingBackwardsNeedsTheRollbackPathNotTheNetwork(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'KEEP-ME'));
        $exit = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v0.1.0', 'OLD')]),
            $this->livePharPath(),
            'v2.0.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit);
        self::assertStringContainsString('refusing to downgrade', $this->errorText());
        self::assertSame('KEEP-ME', \file_get_contents($this->livePharPath()));
        self::assertFileDoesNotExist($this->livePharPath() . '.old');
    }

    public function testTheHappySwapLeavesTheOldBytesInOneShotReserve(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
        // The empty injected key keeps this test on the swap MECHANICS; the
        // pinned default (no injection) is pinned by
        // testTheShippedKeyIsPinnedAndTheDefaultRefusesUnsignedReleases.
        $exit = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(SelfUpdate::EXIT_OK, $exit);
        self::assertSame('UPDATED-PHAR-BYTES', \file_get_contents($this->livePharPath()));
        self::assertSame('ORIGINAL-PHAR-BYTES', \file_get_contents($this->livePharPath() . '.old'));
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)), 'the temp file must not survive the swap');
        $text = $this->errorText();
        self::assertStringContainsString('v0.1.0', $text);
        self::assertStringContainsString('v9.9.9', $text);
    }

    public function testAHashMismatchRefusesBeforeAnythingIsRenamed(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
        $bad = \strrev(\hash('sha256', 'UPDATED-PHAR-BYTES'));
        $exit = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'UPDATED-PHAR-BYTES', $bad . '  ' . SelfUpdate::PHAR_ASSET . "\n")]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit);
        self::assertStringContainsString('Nothing was swapped', $this->errorText());
        self::assertSame('ORIGINAL-PHAR-BYTES', \file_get_contents($this->livePharPath()), 'the live phar is byte-intact after a mismatch');
        self::assertFileDoesNotExist($this->livePharPath() . '.old', 'a refused update never consumes the undo slot');
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)), 'the mismatched temp is deleted');
    }

    public function testSidecarsThatCannotAnswerStopTheVerbBeforeAnyPharByte(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $malformed = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'NEW', 'not-a-digest  candy-top.phar')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $malformed);
        self::assertStringContainsString('sidecar', $this->errorText());

        $this->resetErrorStream();

        $foreignName = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v0.0.9', 'NEW', \hash('sha256', 'NEW') . '  candy-sql.phar')]),
            $this->livePharPath(),
            'v0.0.8',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $foreignName);
        self::assertStringContainsString('candy-sql.phar', $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
    }

    public function testRollbackRestoresByteIdenticalAndIsConsumedByTheRestore(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
        // Keyless ('') injection: this test owns the one-shot rollback
        // mechanic, not the signature leg.
        $installed = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(SelfUpdate::EXIT_OK, $installed);

        $this->resetErrorStream();

        $restored = SelfUpdate::run(['--rollback'], null, null, $this->livePharPath());
        self::assertSame(SelfUpdate::EXIT_OK, $restored);
        self::assertSame('ORIGINAL-PHAR-BYTES', \file_get_contents($this->livePharPath()));
        self::assertFileDoesNotExist($this->livePharPath() . '.old');

        $again = SelfUpdate::run(['--rollback'], null, null, $this->livePharPath());
        self::assertSame(SelfUpdate::EXIT_FAILURE, $again, 'the restore is one-shot: the kept copy is gone');
        self::assertStringContainsString('no kept copy', \strtolower($this->errorText()));
    }

    public function testUnwritableTargetsRefuseByNamedReasonBeforeTheNetwork(): void
    {
        // No route is staged at all: were the writability gates ever
        // crossed, the dead-dial refusal would answer instead of the named
        // one, and these messages pin which fired.
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'X'));

        \chmod($this->dir . '/live', 0o400);
        $dirRefusal = SelfUpdate::run([], $this->fakeServerClient(), self::SERVER_ORIGIN . '/absent.json', $this->livePharPath(), 'v0.1.0');
        \chmod($this->dir . '/live', 0o700);
        self::assertSame(SelfUpdate::EXIT_FAILURE, $dirRefusal);
        self::assertStringContainsString('directory', $this->errorText());
        self::assertStringContainsString($this->dir . '/live', $this->errorText());

        $this->resetErrorStream();

        \chmod($this->livePharPath(), 0o400);
        $pharRefusal = SelfUpdate::run([], $this->fakeServerClient(), self::SERVER_ORIGIN . '/absent.json', $this->livePharPath(), 'v0.1.0');
        \chmod($this->livePharPath(), 0o600);
        self::assertSame(SelfUpdate::EXIT_FAILURE, $pharRefusal);
        self::assertStringContainsString('is not writable', $this->errorText());
    }

    public function testAnEmptyDownloadRefusesLikeAMismatch(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $exit = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', '')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit);
        self::assertStringContainsString('produced no bytes', $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)));
    }

    public function testNoQualifyingReleaseAndADeadFetchBothRefuse(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'X'));
        $none = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'NEW', withSidecar: false)]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $none);
        self::assertStringContainsString('no published candy-top release', $this->errorText());

        $this->resetErrorStream();

        $dead = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            self::SERVER_ORIGIN . '/absent.json',
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $dead);
        self::assertStringContainsString('could not read the release list', $this->errorText());
    }

    public function testUsageErrorsCostExitTwo(): void
    {
        self::assertSame(SelfUpdate::EXIT_USAGE, SelfUpdate::run(['--check', '--rollback']));
        self::assertSame(SelfUpdate::EXIT_USAGE, SelfUpdate::run(['--check', '--allow-unsigned']));
        self::assertSame(SelfUpdate::EXIT_USAGE, SelfUpdate::run(['extra']));
        // Unknown flags read as operands to a flags-only verb: the parser is
        // the allow-list, so --allow-remote never reaches the machinery.
        self::assertSame(SelfUpdate::EXIT_USAGE, SelfUpdate::run(['--allow-remote']));
    }

    public function testSelectionSkipsEverythingThatIsNotAStableCandyTopPair(): void
    {
        $entries = [
            $this->stageRelease('candy-sql/v99.0.0', 'OTHER-LIB'),
            $this->stageRelease('candy-top/v0.9.0', 'NINE'),
            $this->stageRelease('candy-top/v99.0.0', 'PRE', prerelease: true),
            $this->stageRelease('candy-top/v88.0.0', 'DRAFT', draft: true),
            $this->stageRelease('candy-top/v77.0.0', 'NOSIG', withSidecar: false),
            $this->stageRelease('candy-top/dev-master', 'UNORDERABLE'),
            $this->stageRelease('candy-top/v0.10.0', 'TEN'),
        ];
        $this->releasesUrl($entries);
        $decoded = \json_decode((string) \file_get_contents($this->dir . '/srv/releases.json'), true);
        $selected = SelfUpdate::selectRelease($decoded);
        self::assertIsArray($selected);
        // String ordering would pick v0.9.0 over v0.10.0; numeric ordering
        // is what the sidecar-pinned swap runs on.
        self::assertSame('v0.10.0', $selected['version']);
        self::assertStringEndsWith('candy-top-v0.10.0.phar', $selected['pharUrl']);
        self::assertStringEndsWith('candy-top-v0.10.0.sha256', $selected['sidecarUrl']);
        // None of the staged releases carries a signature asset: a keyless
        // release stays SELECTABLE, and its sig URL is the empty string the
        // pinned-key leg reads as "absent", never a guess.
        self::assertSame('', $selected['sigUrl']);

        self::assertNull(SelfUpdate::selectRelease([]));
        self::assertNull(SelfUpdate::selectRelease([['tag_name' => 'candy-top/v1.0.0']]));
    }

    public function testSidecarParsingFailsClosedOnEveryOtherShape(): void
    {
        $hex = \hash('sha256', 'anything');
        self::assertSame($hex, SelfUpdate::parseSidecar($hex . '  candy-top.phar' . "\n"));
        self::assertSame($hex, SelfUpdate::parseSidecar($hex));
        self::assertNull(SelfUpdate::parseSidecar(\strtoupper($hex) . '  candy-top.phar'));
        self::assertNull(SelfUpdate::parseSidecar(\substr($hex, 1) . '  candy-top.phar'));
        self::assertNull(SelfUpdate::parseSidecar($hex . "\n" . $hex . '  candy-top.phar'));
        self::assertSame(SelfUpdate::PHAR_ASSET, SelfUpdate::sidecarName($hex . '  candy-top.phar'));
        self::assertSame('', SelfUpdate::sidecarName($hex));
        self::assertSame('other.phar', SelfUpdate::sidecarName($hex . '  other.phar'));
    }

    public function testTheVersionHelperTheVerbRunsOnOrdersWhatItMust(): void
    {
        self::assertSame(1, Version::compare('v0.10.0', 'v0.9.0'));
        self::assertSame(-1, Version::compare('v0.1.0', 'v1.0.0'));
        self::assertSame(0, Version::compare('v0.1.0', '0.1.0'));
        self::assertNull(Version::compare('dev-master', 'v0.1.0'));
        self::assertFalse(Version::isComparable('dev-master'));
        self::assertTrue(Version::isComparable('v1.2.3'));
    }

    /**
     * DST-1: the bug that shipped candy-code's first phar dead-on-arrival.
     * Every other pin in this file injects the current version — production
     * does not, and the non-injected path can resolve through the frozen
     * `InstalledVersions` baked into the archive (`dev-master`), which is not
     * orderable, so every real invocation — `--check` included, even when up
     * to date — would exit 1 on "uncomparable". This drives run() with NO
     * $currentVersion against a real stamped archive (minted by child
     * process, read by `Version::fromPharMetadata`): the stamp must win over
     * whatever the Composer metadata guesses, and the compare/swap legs must
     * run on it.
     */
    public function testTheRealResolutionPathOrdersAgainstTheStampedArchive(): void
    {
        $stamped = self::mintStampedPhar('candy-top/v0.1.0');
        Help::pretendHostArchiveForTesting($stamped);

        try {
            $available = SelfUpdate::run(
                ['--check'],
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'NEW')]),
                $this->livePharPath(),
            );
            self::assertSame(SelfUpdate::EXIT_FAILURE, $available);
            $text = $this->errorText();
            self::assertStringContainsString('update available', $text);
            self::assertStringContainsString('v0.1.0', $text, 'the stamp, not the frozen composer guess, is what --check reported as this install');
            self::assertStringNotContainsString('dev-master', $text, 'the frozen dev guess must not reach the report from inside a stamped archive');

            $this->resetErrorStream();

            $upToDate = SelfUpdate::run(
                ['--check'],
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-top/v0.1.0', 'NEW')]),
                $this->livePharPath(),
            );
            self::assertSame(SelfUpdate::EXIT_OK, $upToDate, 'a stamped install CAN be recognised as current — a dev-guess-phar could never');
            self::assertStringContainsString('up to date', $this->errorText());

            $this->resetErrorStream();

            self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
            // null $currentVersion keeps the DST-1 "no version injection"
            // story; '' injects the keyless shape so this test owns version
            // resolution, not the signature leg.
            $installed = SelfUpdate::run(
                [],
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', 'UPDATED-PHAR-BYTES')]),
                $this->livePharPath(),
                null,
                '',
            );
            self::assertSame(SelfUpdate::EXIT_OK, $installed, 'the full swap runs off the real resolution, not just --check');
            self::assertStringContainsString('v0.1.0 -> v9.9.9', $this->errorText());
            self::assertSame('UPDATED-PHAR-BYTES', \file_get_contents($this->livePharPath()));
        } finally {
            Help::pretendHostArchiveForTesting(null);
            self::discardStampedPhar($stamped);
        }
    }

    /**
     * DST-3: the pinned-key leg in all its polarities, driven through the
     * $publicKey parameter (production reads the same code off
     * `SelfUpdate::PUBLIC_KEY_BASE64`, pinned at candy-top's birth 2026-10-21
     * — the production default itself is pinned by
     * {@see testTheShippedKeyIsPinnedAndTheDefaultRefusesUnsignedReleases},
     * and leg d pins the keyless shape the release stream would have worn
     * had the secret not landed). The fixture signatures are precomputed so a
     * PHP built without ext-sodium still judges every leg: the source's own
     * function_exists guard makes it refuse loudly there, and that
     * fail-closed polarity is what leg e asserts when the extension is
     * absent.
     */
    public function testThePinnedKeyLegStopsWhatItPromisesToStop(): void
    {
        // a. forged-but-structurally-valid signature: loud refusal either way.
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $forged = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_FORGED_SIGNATURE . "\n")]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $forged);
        self::assertStringContainsString('does not verify', $this->errorText());
        self::assertStringContainsString(SelfUpdate::SIGNATURE_ASSET, $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        self::assertFileDoesNotExist($this->livePharPath() . '.old');
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)), 'the refused temp is deleted');

        $this->resetErrorStream();

        // b. key populated, release carries no minisig: refusal naming the bypass.
        $missing = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $missing);
        $text = $this->errorText();
        self::assertStringContainsString('carries no ' . SelfUpdate::SIGNATURE_ASSET, $text);
        self::assertStringContainsString('--allow-unsigned', $text);
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();

        // c. --allow-unsigned bypasses the signature leg alone — the sha256
        // sidecar still certified these bytes before the notice printed.
        $bypassed = SelfUpdate::run(
            ['--allow-unsigned'],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(SelfUpdate::EXIT_OK, $bypassed);
        $text = $this->errorText();
        self::assertStringContainsString('--allow-unsigned skips', $text);
        self::assertStringContainsString('sha256', $text, 'the notice must say what still bound the bytes');
        self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));

        // d. keyless state (the shape a build ships when the repo secret is
        // absent, simulated by injecting the empty key): a release that DOES
        // carry a minisig swaps on the sidecar alone — the signature is
        // ignored without a key to verify it against.
        $keyless = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_VALID_SIGNATURE)]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(SelfUpdate::EXIT_OK, $keyless, 'an empty key is the documented keyless state, not a half-enabled pinned mode');
        self::assertStringNotContainsString('--allow-unsigned skips', $this->errorText());
        self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));

        // e. the valid signature under the matching key. With ext-sodium the
        // crypto decides: swap. Without it, verifyDetached's function_exists
        // guard fails CLOSED: refusal. Both polarities are correct behavior;
        // silence here would let an unverifiable artifact through.
        $verified = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_VALID_SIGNATURE)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        if (\function_exists('sodium_crypto_sign_verify_detached')) {
            self::assertSame(SelfUpdate::EXIT_OK, $verified);
            self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));
        } else {
            self::assertSame(SelfUpdate::EXIT_FAILURE, $verified, 'without sodium the pinned leg refuses rather than trusts');
            self::assertStringContainsString('does not verify', $this->errorText());
            self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        }
    }

    /**
     * DST-3 owner action (pinned at candy-top's birth 2026-10-21): the
     * production DEFAULT is pinned state from this project's first commit.
     * Half one pins the constant's shape: non-empty, strict base64 decoding
     * to exactly the 32-byte raw public key verifyDetached() parses, and
     * distinct from this file's test-only fixture key (a pinned key that
     * matched the fixture would make every fixture leg above vacuous). Half
     * two drives run() with NO key injection — the exact production shape —
     * against a release lacking the minisig asset: the pinned default must
     * refuse it loudly. Blanking the constant reddens both halves.
     */
    public function testTheShippedKeyIsPinnedAndTheDefaultRefusesUnsignedReleases(): void
    {
        self::assertNotSame('', SelfUpdate::PUBLIC_KEY_BASE64, 'the shipped constant is the PINNED state since 2026-10-21 — blanking it silently downgrades every build to keyless');
        $raw = \base64_decode(SelfUpdate::PUBLIC_KEY_BASE64, true);
        self::assertNotFalse($raw, 'the constant must be strict base64');
        self::assertSame(32, \strlen((string) $raw), 'the constant must be the base64 of the raw 32-byte sodium public key exactly as verifyDetached() parses it');
        self::assertNotSame(self::FIXTURE_PUBLIC_KEY_BASE64, SelfUpdate::PUBLIC_KEY_BASE64, 'production and fixture keys must stay distinct or the fixture legs prove nothing');

        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $exit = SelfUpdate::run(
            [],
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-top/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(SelfUpdate::EXIT_FAILURE, $exit, 'the production default (no key injection) is pinned state: an unsigned release is refused');
        self::assertStringContainsString('carries no ' . SelfUpdate::SIGNATURE_ASSET, $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()), 'the refusal never touches the live bytes');
    }

    /**
     * DST-3: the pure verifier's malformed-input refusals, pinned at the unit
     * level so the end-to-end legs above can own their own story. Every shape
     * here answers false WITHOUT reaching crypto: the guard order (empty
     * payload → missing-extension check → strict base64 → exact lengths) is
     * what makes a hostile asset fail closed on any runner.
     */
    public function testVerifyDetachedRefusesEveryMalformedShape(): void
    {
        self::assertFalse(SelfUpdate::verifyDetached('', self::FIXTURE_VALID_SIGNATURE, self::FIXTURE_PUBLIC_KEY_BASE64), 'an empty payload never verifies, extension or not');
        self::assertFalse(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, 'not base64 at all!!', self::FIXTURE_PUBLIC_KEY_BASE64), 'non-strict base64 in the signature slot is a refusal, not a crash');
        self::assertFalse(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, self::FIXTURE_VALID_SIGNATURE, 'not-a-key'), 'non-strict base64 in the key slot likewise');
        self::assertFalse(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, self::FIXTURE_VALID_SIGNATURE, \base64_encode('short-key')), 'a key that is not SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES raw bytes is refused');
        self::assertFalse(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, \base64_encode('short-sig'), self::FIXTURE_PUBLIC_KEY_BASE64), 'a signature that is not SODIUM_CRYPTO_SIGN_BYTES raw bytes is refused');

        if (\function_exists('sodium_crypto_sign_verify_detached')) {
            self::assertTrue(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, self::FIXTURE_VALID_SIGNATURE, self::FIXTURE_PUBLIC_KEY_BASE64), 'the fixture pair must verify where crypto exists — otherwise leg a proves nothing');
            self::assertFalse(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, self::FIXTURE_FORGED_SIGNATURE, self::FIXTURE_PUBLIC_KEY_BASE64));
            // The surrounding-whitespace tolerance the sidecar pattern uses (a
            // trailing newline from `echo` must not fail a real signature).
            self::assertTrue(SelfUpdate::verifyDetached(self::SIGNED_PAYLOAD_BYTES, ' ' . self::FIXTURE_VALID_SIGNATURE . "\n", self::FIXTURE_PUBLIC_KEY_BASE64));
        }
    }

    /**
     * DST-3: selection reports the signature URL when the release carries
     * the asset, and the keyless leg of the roster stays selectable — the
     * asset's presence is a fact about the release, the requirement is a
     * fact about the pinned key state, and the two must not be conflated.
     */
    public function testSelectionReportsTheSignatureUrlOnlyWhenTheReleaseCarriesOne(): void
    {
        $entries = [
            $this->stageRelease('candy-top/v1.0.0', 'OLD-ONE'),
            $this->stageRelease('candy-top/v2.0.0', 'NEW-TWO', sigBody: self::FIXTURE_VALID_SIGNATURE),
        ];
        $this->releasesUrl($entries);
        $decoded = \json_decode((string) \file_get_contents($this->dir . '/srv/releases.json'), true);

        $selected = SelfUpdate::selectRelease($decoded);
        self::assertIsArray($selected);
        self::assertSame('v2.0.0', $selected['version']);
        self::assertStringEndsWith('candy-top-v2.0.0.minisig', $selected['sigUrl']);
    }
}

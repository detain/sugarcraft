<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use Composer\InstalledVersions;
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
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\Help;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\SelfUpdate;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Support\Version;
use SugarCraft\Code\Tests\Support\MintsStampedPharTrait;

/**
 * DST-3: `candy-code self-update` end to end against a fake release server.
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

    private const SERVER_ORIGIN = 'http://fake-candy-code-server.invalid';

    /**
     * TEST-ONLY Ed25519 keypair (DST-3 signature pins): generated once by
     * `sodium_crypto_sign_keypair()` on the build box and frozen here so the
     * pins verify without signing at runtime — a runner whose PHP was built
     * without ext-sodium still exercises every keyless and fail-closed
     * polarity through the same constants. Nothing real is signed: the key
     * has no identity outside this file.
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
        $this->dir = \sys_get_temp_dir() . '/dst3-su-' . \bin2hex(\random_bytes(4));
        self::assertTrue(\mkdir($this->dir . '/srv', 0o700, true));
        self::assertTrue(\mkdir($this->dir . '/live', 0o700, true));
        // The writability gates come before any fetch, so every scenario
        // needs an installed phar standing in the live slot.
        self::assertNotFalse(\file_put_contents($this->dir . '/live/candy-code.phar', 'INSTALLED-PHAR-BYTES'));
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

    /** @param list<string> $argv */
    private static function selfUpdateArgs(array $argv): ParsedArgs
    {
        return ArgvParser::parse(['candy-code', ...$argv]);
    }

    /**
     * The fake release server: any GET under the origin is answered from
     * srv/<path>; unknown routes reject with the ConnectException a dead
     * dial would produce. The sink option is honoured because the production
     * download asks for one.
     *
     * $statusByPath overrides the standing 200 per route — the download legs
     * judge HTTP status as their own concern (DST-3-P4 pins), which is only
     * observable when the client carries http_errors disabled exactly as the
     * production client does (httpClient()); a 404 must arrive as a plain
     * Response, not as a Guzzle-generated RequestException. $linkByPath
     * stamps a Link header on a route so the selection walk can be made to
     * page (DST-3-P5). $requestLog, taken by reference, collects the route
     * paths in arrival order so a test can prove EXACTLY how far a walk went
     * — not just where it ended.
     *
     * @param array<string, int>    $statusByPath
     * @param array<string, string> $linkByPath
     * @param list<string>|null     $requestLog
     */
    private function fakeServerClient(array $statusByPath = [], array $linkByPath = [], ?array &$requestLog = null): Client
    {
        $srv = $this->dir . '/srv';
        $handler = static function (RequestInterface $request, array $options) use ($srv, $statusByPath, $linkByPath, &$requestLog): PromiseInterface {
            $path = $request->getUri()->getPath();
            if ($requestLog === null) {
                $requestLog = [];
            }
            $requestLog[] = $path;
            $file = $srv . \rawurldecode($path);
            if (!\is_file($file)) {
                return new RejectedPromise(new ConnectException(
                    'fake server has no route ' . $path,
                    $request,
                ));
            }
            $status = $statusByPath[$path] ?? 200;
            $headers = [];
            if (isset($linkByPath[$path])) {
                $headers['Link'] = $linkByPath[$path];
            }
            $body = (string) \file_get_contents($file);
            $headers['Content-Length'] = (string) \strlen($body);
            if (isset($options['sink']) && \is_string($options['sink'])) {
                if (\file_put_contents($options['sink'], $body) === false) {
                    return new RejectedPromise(new ConnectException('fake sink write failed', $request));
                }

                return new FulfilledPromise(new Response($status, $headers));
            }

            return new FulfilledPromise(new Response($status, $headers, Utils::streamFor($body)));
        };

        // Mirrors httpClient(): http_errors off, so a 4xx reaches the legs as a
        // plain Response and their own status judgement is what speaks.
        return new Client([
            'handler' => HandlerStack::create($handler),
            'http_errors' => false,
        ]);
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
        return $this->dir . '/live/candy-code.phar';
    }

    /**
     * Stage one release on the fake server and return its JSON entry. The
     * sidecar defaults to the true digest of the staged phar in sha256sum's
     * own '<hex>  <name>' spelling. $commitish stages the GitHub release
     * field the dev channel reads; null OMITS the key, so every fixture
     * that predates channels serializes byte-identically.
     *
     * @return array<array-key, mixed>
     */
    private function stageRelease(string $tag, string $pharBytes, ?string $sidecarBody = null, bool $withSidecar = true, bool $draft = false, bool $prerelease = false, ?string $sigBody = null, ?string $commitish = null): array
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

        $entry = [
            'tag_name' => $tag,
            'draft' => $draft,
            'prerelease' => $prerelease,
            'assets' => $assets,
        ];
        if ($commitish !== null) {
            $entry['target_commitish'] = $commitish;
        }

        return $entry;
    }

    /**
     * @param list<array<array-key, mixed>> $entries
     * @param string $name the route file is served under, so a paged walk
     *                     can stage distinct pages at distinct paths.
     */
    private function releasesUrl(array $entries, string $name = 'releases.json'): string
    {
        self::assertNotFalse(\file_put_contents($this->dir . '/srv/' . $name, \json_encode($entries, \JSON_THROW_ON_ERROR)));

        return self::SERVER_ORIGIN . '/' . $name;
    }

    public function testTheRosterCarriesElevenVerbsWithSelfUpdateInAlphaOrder(): void
    {
        $accepted = ParsedArgs::SUBCOMMANDS;
        sort($accepted);
        self::assertSame(
            ['acp', 'attach', 'bundles', 'completion', 'doctor', 'login', 'mcp', 'models', 'self-update', 'serve', 'session'],
            $accepted,
        );
        self::assertTrue(class_exists(ArgvParser::class));
        self::assertStringContainsString(
            "'self-update' => SelfUpdate::run(\$args),",
            (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Subcommands.php'),
            'the dispatch arm for the verb left Subcommands::dispatch — the roster would lie',
        );
    }

    public function testTheVerbsOwnFlagsParseAndForeignOnesStayUnknown(): void
    {
        $check = self::selfUpdateArgs(['self-update', '--check']);
        self::assertSame('self-update', $check->subcommand);
        self::assertSame(['--check' => true], $check->subcommandFlags);
        self::assertSame([], $check->unknownFlags);

        $rollback = self::selfUpdateArgs(['self-update', '--rollback']);
        self::assertSame(['--rollback' => true], $rollback->subcommandFlags);

        $bypass = self::selfUpdateArgs(['self-update', '--allow-unsigned']);
        self::assertSame(['--allow-unsigned' => true], $bypass->subcommandFlags);
        self::assertSame([], $bypass->unknownFlags);

        // The parser accepts the combination; run() is what refuses it (pinned
        // in testUsageErrorsCostExitTwo), because only run() knows --check reports.
        $mixed = self::selfUpdateArgs(['self-update', '--check', '--allow-unsigned']);
        self::assertSame(['--check' => true, '--allow-unsigned' => true], $mixed->subcommandFlags);
        self::assertSame([], $mixed->unknownFlags);

        $channeled = self::selfUpdateArgs(['self-update', '--check', '--channel=dev']);
        self::assertSame(['--check' => true, '--channel' => 'dev'], $channeled->subcommandFlags);
        self::assertSame([], $channeled->unknownFlags);
        $spaced = self::selfUpdateArgs(['self-update', '--channel', 'stable']);
        self::assertSame(['--channel' => 'stable'], $spaced->subcommandFlags);
        self::assertSame([], $spaced->unknownFlags);

        self::assertSame(['--check'], self::selfUpdateArgs(['--check', 'self-update'])->unknownFlags);
        self::assertSame(['--allow-remote'], self::selfUpdateArgs(['self-update', '--allow-remote'])->unknownFlags);
    }

    public function testAComposerInstallIsRefusedLoudlyAndNeverSilently(): void
    {
        // Production shape: no seams at all. The test process is not inside a
        // phar, so Phar::running() answers '' — the refusal must fire BEFORE
        // any client is built (a silent no-op here would exit 0).
        $exit = SelfUpdate::run(self::selfUpdateArgs(['self-update']));
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        $text = $this->errorText();
        self::assertStringContainsString('Composer', $text);
        // The named command fits the install shape (DST-3's refusal branch):
        // this suite's Composer root package IS sugarcraft/candy-code, so the
        // honest answer printed here is the clone pull, not a
        // self-referential dependency update. Both install shapes are pinned
        // directly on composerRefusal in the test below; this one only
        // asserts the shape the live runtime actually reports.
        $root = \class_exists(InstalledVersions::class)
            ? (InstalledVersions::getRootPackage()['name'] ?? '')
            : '';
        if ($root === 'sugarcraft/candy-code') {
            self::assertStringContainsString(SelfUpdate::PULL_AND_INSTALL_COMMAND, $text);
        } else {
            self::assertStringContainsString(SelfUpdate::COMPOSER_COMMAND, $text);
            self::assertStringContainsString('composer global update sugarcraft/candy-code', $text);
        }
        self::assertSame(SelfUpdate::composerRefusal(), \trim($text), 'the refusal is the documented sentence verbatim');
    }

    public function testCheckDistinguishesAvailableFromUpToDateByExitCode(): void
    {
        $available = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $available, '--check must exit 1 when a newer release exists');
        self::assertStringContainsString('update available', $this->errorText());

        $this->resetErrorStream();

        $upToDate = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v0.1.0', 'NEW')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_OK, $upToDate, 'an up-to-date --check is success, not failure');
        self::assertStringContainsString('up to date', $this->errorText());
    }

    public function testCheckOnAnUnorderableInstallSaysSo(): void
    {
        // PHA moved this fixture from 'dev-master' — that string is now a
        // DEV classifier answer (its own channel pins below), not a
        // stable-channel dead end — to the 'unknown' Help::versionString()
        // arm: same story (a version compare() refuses to order, on the
        // stable train), honestly re-armed.
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'unknown',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString('cannot compare', $this->errorText());
    }

    public function testGoingBackwardsNeedsTheRollbackPathNotTheNetwork(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'KEEP-ME'));
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v0.1.0', 'OLD')]),
            $this->livePharPath(),
            'v2.0.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
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
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(NonInteractive::EXIT_OK, $exit);
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
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES', $bad . '  ' . SelfUpdate::PHAR_ASSET . "\n")]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString('Nothing was swapped', $this->errorText());
        self::assertSame('ORIGINAL-PHAR-BYTES', \file_get_contents($this->livePharPath()), 'the live phar is byte-intact after a mismatch');
        self::assertFileDoesNotExist($this->livePharPath() . '.old', 'a refused update never consumes the undo slot');
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)), 'the mismatched temp is deleted');
    }

    public function testSidecarsThatCannotAnswerStopTheVerbBeforeAnyPharByte(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $malformed = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW', 'not-a-digest  candy-code.phar')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $malformed);
        self::assertStringContainsString('sidecar', $this->errorText());

        $this->resetErrorStream();

        $foreignName = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v0.0.9', 'NEW', \hash('sha256', 'NEW') . '  candy-sql.phar')]),
            $this->livePharPath(),
            'v0.0.8',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $foreignName);
        self::assertStringContainsString('candy-sql.phar', $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
    }

    public function testRollbackRestoresByteIdenticalAndIsConsumedByTheRestore(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
        // Keyless ('') injection: this test owns the one-shot rollback
        // mechanic, not the signature leg.
        $installed = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(NonInteractive::EXIT_OK, $installed);

        $this->resetErrorStream();

        $restored = SelfUpdate::run(self::selfUpdateArgs(['self-update', '--rollback']), null, null, $this->livePharPath());
        self::assertSame(NonInteractive::EXIT_OK, $restored);
        self::assertSame('ORIGINAL-PHAR-BYTES', \file_get_contents($this->livePharPath()));
        self::assertFileDoesNotExist($this->livePharPath() . '.old');

        $again = SelfUpdate::run(self::selfUpdateArgs(['self-update', '--rollback']), null, null, $this->livePharPath());
        self::assertSame(NonInteractive::EXIT_FAILURE, $again, 'the restore is one-shot: the kept copy is gone');
        self::assertStringContainsString('no kept copy', \strtolower($this->errorText()));
    }

    public function testUnwritableTargetsRefuseByNamedReasonBeforeTheNetwork(): void
    {
        // No route is staged at all: were the writability gates ever
        // crossed, the dead-dial refusal would answer instead of the named
        // one, and these messages pin which fired.
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'X'));

        \chmod($this->dir . '/live', 0o400);
        $dirRefusal = SelfUpdate::run(self::selfUpdateArgs(['self-update']), $this->fakeServerClient(), self::SERVER_ORIGIN . '/absent.json', $this->livePharPath(), 'v0.1.0');
        \chmod($this->dir . '/live', 0o700);
        self::assertSame(NonInteractive::EXIT_FAILURE, $dirRefusal);
        self::assertStringContainsString('directory', $this->errorText());
        self::assertStringContainsString($this->dir . '/live', $this->errorText());

        $this->resetErrorStream();

        \chmod($this->livePharPath(), 0o400);
        $pharRefusal = SelfUpdate::run(self::selfUpdateArgs(['self-update']), $this->fakeServerClient(), self::SERVER_ORIGIN . '/absent.json', $this->livePharPath(), 'v0.1.0');
        \chmod($this->livePharPath(), 0o600);
        self::assertSame(NonInteractive::EXIT_FAILURE, $pharRefusal);
        self::assertStringContainsString('is not writable', $this->errorText());

        // DST-3: the Windows residual of the same "target refuses the swap"
        // family is documented, not silently unhandled — renaming over a
        // live image fails on NT and the class must say the last leg dies
        // LOUD rather than leaving readers hunting for a half-swap.
        $doc = (string) (new \ReflectionClass(SelfUpdate::class))->getDocComment();
        self::assertStringContainsString('WINDOWS CAVEAT', $doc, 'the class docblock lost its Windows active-image caveat — the final rename() leg fails loud on NT and the file must document it');
        self::assertStringContainsString('rename_failed', $doc, 'the caveat no longer names the refusal the Windows leg emits');
    }

    public function testAnEmptyDownloadRefusesLikeAMismatch(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', '')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString('produced no bytes', $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)));
    }

    public function testNoQualifyingReleaseAndADeadFetchBothRefuse(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'X'));
        $none = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW', withSidecar: false)]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $none);
        self::assertStringContainsString('no published candy-code release', $this->errorText());

        $this->resetErrorStream();

        $dead = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            self::SERVER_ORIGIN . '/absent.json',
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $dead);
        self::assertStringContainsString('could not read the release list', $this->errorText());
    }

    public function testUsageErrorsCostExitTwo(): void
    {
        self::assertSame(NonInteractive::EXIT_CONFIG, SelfUpdate::run(self::selfUpdateArgs(['self-update', '--check', '--rollback'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, SelfUpdate::run(self::selfUpdateArgs(['self-update', '--check', '--allow-unsigned'])));
        self::assertSame(NonInteractive::EXIT_CONFIG, SelfUpdate::run(self::selfUpdateArgs(['self-update', 'extra'])));
    }

    public function testSelectionSkipsEverythingThatIsNotAStableCandyCodePair(): void
    {
        $entries = [
            $this->stageRelease('candy-sql/v99.0.0', 'OTHER-LIB'),
            $this->stageRelease('candy-code/v0.9.0', 'NINE'),
            $this->stageRelease('candy-code/v99.0.0', 'PRE', prerelease: true),
            $this->stageRelease('candy-code/v88.0.0', 'DRAFT', draft: true),
            $this->stageRelease('candy-code/v77.0.0', 'NOSIG', withSidecar: false),
            $this->stageRelease('candy-code/dev-master', 'UNORDERABLE'),
            $this->stageRelease('candy-code/v0.10.0', 'TEN'),
            // PHA: the dev train must be invisible to the stable filter —
            // prerelease, draft, and plain spellings of the dev tag all
            // skip (a version-less tag also fails isComparable by design).
            $this->stageRelease('candy-code/dev', 'DEVPRERELEASE', prerelease: true, commitish: '1a2b3c4def1a2b3c4def1a2b3c4def1a2b3c4def'),
            $this->stageRelease('candy-code/dev', 'DEVDRAFT', draft: true, commitish: '1a2b3c4def1a2b3c4def1a2b3c4def1a2b3c4def'),
            $this->stageRelease('candy-code/dev', 'DEVPLAIN', commitish: '1a2b3c4def1a2b3c4def1a2b3c4def1a2b3c4def'),
        ];
        $this->releasesUrl($entries);
        $decoded = \json_decode((string) \file_get_contents($this->dir . '/srv/releases.json'), true);
        $selected = SelfUpdate::selectRelease($decoded);
        self::assertIsArray($selected);
        // String ordering would pick v0.9.0 over v0.10.0; numeric ordering
        // is what the sidecar-pinned swap runs on.
        self::assertSame('v0.10.0', $selected['version']);
        self::assertStringEndsWith('candy-code-v0.10.0.phar', $selected['pharUrl']);
        self::assertStringEndsWith('candy-code-v0.10.0.sha256', $selected['sidecarUrl']);
        // None of the staged releases carries a signature asset: a keyless
        // release stays SELECTABLE, and its sig URL is the empty string the
        // pinned-key leg reads as "absent", never a guess.
        self::assertSame('', $selected['sigUrl']);

        self::assertNull(SelfUpdate::selectRelease([]));
        self::assertNull(SelfUpdate::selectRelease([['tag_name' => 'candy-code/v1.0.0']]));
    }

    public function testSidecarParsingFailsClosedOnEveryOtherShape(): void
    {
        $hex = \hash('sha256', 'anything');
        self::assertSame($hex, SelfUpdate::parseSidecar($hex . '  candy-code.phar' . "\n"));
        self::assertSame($hex, SelfUpdate::parseSidecar($hex));
        self::assertNull(SelfUpdate::parseSidecar(\strtoupper($hex) . '  candy-code.phar'));
        self::assertNull(SelfUpdate::parseSidecar(\substr($hex, 1) . '  candy-code.phar'));
        self::assertNull(SelfUpdate::parseSidecar($hex . "\n" . $hex . '  candy-code.phar'));
        self::assertSame(SelfUpdate::PHAR_ASSET, SelfUpdate::sidecarName($hex . '  candy-code.phar'));
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
     * DST-1: the bug that shipped dead-on-arrival. Every other pin in this
     * file injects the current version — production does not, and the
     * non-injected path resolved through the frozen `InstalledVersions`
     * baked into the archive (`dev-master (311f572)` on the shipped 0.1.0
     * phar), so `isComparable` was false and every real invocation —
     * `--check` included, even when up to date — exited 1 on
     * "uncomparable". This drives run() with NO $currentVersion against a
     * real stamped archive (minted by child process, read by
     * `Version::fromPharMetadata`): the stamp must win over whatever the
     * Composer metadata guesses, and the compare/swap legs must run on it.
     */
    public function testTheRealResolutionPathOrdersAgainstTheStampedArchive(): void
    {
        $stamped = self::mintStampedPhar('candy-code/v0.1.0');
        Help::pretendHostArchiveForTesting($stamped);

        try {
            $available = SelfUpdate::run(
                self::selfUpdateArgs(['self-update', '--check']),
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW')]),
                $this->livePharPath(),
            );
            self::assertSame(NonInteractive::EXIT_FAILURE, $available);
            $text = $this->errorText();
            self::assertStringContainsString('update available', $text);
            self::assertStringContainsString('v0.1.0', $text, 'the stamp, not the frozen composer guess, is what --check reported as this install');
            self::assertStringNotContainsString('dev-master', $text, 'the frozen dev guess must not reach the report from inside a stamped archive');

            $this->resetErrorStream();

            $upToDate = SelfUpdate::run(
                self::selfUpdateArgs(['self-update', '--check']),
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-code/v0.1.0', 'NEW')]),
                $this->livePharPath(),
            );
            self::assertSame(NonInteractive::EXIT_OK, $upToDate, 'a stamped install CAN be recognised as current — the shipped phar could never');
            self::assertStringContainsString('up to date', $this->errorText());

            $this->resetErrorStream();

            self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL-PHAR-BYTES'));
            // null $currentVersion keeps the DST-1 "no version injection"
            // story; '' injects the keyless shape so this test owns version
            // resolution, not the signature leg.
            $installed = SelfUpdate::run(
                self::selfUpdateArgs(['self-update']),
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
                $this->livePharPath(),
                null,
                '',
            );
            self::assertSame(NonInteractive::EXIT_OK, $installed, 'the full swap runs off the real resolution, not just --check');
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
     * `SelfUpdate::PUBLIC_KEY_BASE64`, pinned 2026-10-20 — the production
     * default itself is pinned by
     * {@see testTheShippedKeyIsPinnedAndTheDefaultRefusesUnsignedReleases},
     * and leg d pins the pre-pinned keyless shape). The fixture signatures
     * are precomputed so a PHP built without ext-sodium still judges every
     * leg: the source's own function_exists guard makes it refuse loudly
     * there, and that fail-closed polarity is what leg e asserts when the
     * extension is absent.
     */
    public function testThePinnedKeyLegStopsWhatItPromisesToStop(): void
    {
        // a. forged-but-structurally-valid signature: loud refusal either way.
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $forged = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_FORGED_SIGNATURE . "\n")]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $forged);
        self::assertStringContainsString('does not verify', $this->errorText());
        self::assertStringContainsString(SelfUpdate::SIGNATURE_ASSET, $this->errorText());
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        self::assertFileDoesNotExist($this->livePharPath() . '.old');
        self::assertSame([], \glob($this->livePharPath() . '.tmp-' . \chr(42)), 'the refused temp is deleted');

        $this->resetErrorStream();

        // b. key populated, release carries no minisig: refusal naming the bypass.
        $missing = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $missing);
        $text = $this->errorText();
        self::assertStringContainsString('carries no ' . SelfUpdate::SIGNATURE_ASSET, $text);
        self::assertStringContainsString('--allow-unsigned', $text);
        self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();

        // c. --allow-unsigned bypasses the signature leg alone — the sha256
        // sidecar still certified these bytes before the notice printed.
        $bypassed = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--allow-unsigned']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(NonInteractive::EXIT_OK, $bypassed);
        $text = $this->errorText();
        self::assertStringContainsString('--allow-unsigned skips', $text);
        self::assertStringContainsString('sha256', $text, 'the notice must say what still bound the bytes');
        self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));

        // d. keyless state (the shape of every phar shipped before the key
        // was pinned 2026-10-20, simulated by injecting the empty key): a
        // release that DOES carry a minisig swaps on the sidecar alone — the
        // signature is ignored without a key to verify it against.
        $keyless = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_VALID_SIGNATURE)]),
            $this->livePharPath(),
            'v0.1.0',
            '',
        );
        self::assertSame(NonInteractive::EXIT_OK, $keyless, 'an empty key is the documented keyless state, not a half-enabled pinned mode');
        self::assertStringNotContainsString('--allow-unsigned skips', $this->errorText());
        self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));

        $this->resetErrorStream();
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));

        // e. the valid signature under the matching key. With ext-sodium the
        // crypto decides: swap. Without it, verifyDetached's function_exists
        // guard fails CLOSED: refusal. Both polarities are correct behavior;
        // silence here would let an unverifiable artifact through.
        $verified = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_VALID_SIGNATURE)]),
            $this->livePharPath(),
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        if (\function_exists('sodium_crypto_sign_verify_detached')) {
            self::assertSame(NonInteractive::EXIT_OK, $verified);
            self::assertSame(self::SIGNED_PAYLOAD_BYTES, \file_get_contents($this->livePharPath()));
        } else {
            self::assertSame(NonInteractive::EXIT_FAILURE, $verified, 'without sodium the pinned leg refuses rather than trusts');
            self::assertStringContainsString('does not verify', $this->errorText());
            self::assertSame('ORIGINAL', \file_get_contents($this->livePharPath()));
        }
    }

    /**
     * DST-3 owner action (pinned 2026-10-20): the production DEFAULT is now
     * pinned state. Half one pins the constant's shape: non-empty, strict
     * base64 decoding to exactly the 32-byte raw public key verifyDetached()
     * parses, and distinct from this file's test-only fixture key (a pinned
     * key that matched the fixture would make every fixture leg above
     * vacuous). Half two drives run() with NO key injection — the exact
     * production shape — against a release lacking the minisig asset: the
     * pinned default must refuse it loudly. Blanking the constant reddens
     * both halves.
     */
    public function testTheShippedKeyIsPinnedAndTheDefaultRefusesUnsignedReleases(): void
    {
        self::assertNotSame('', SelfUpdate::PUBLIC_KEY_BASE64, 'the shipped constant is the PINNED state since 2026-10-20 — blanking it silently downgrades every build to keyless');
        $raw = \base64_decode(SelfUpdate::PUBLIC_KEY_BASE64, true);
        self::assertNotFalse($raw, 'the constant must be strict base64');
        self::assertSame(32, \strlen((string) $raw), 'the constant must be the base64 of the raw 32-byte sodium public key exactly as verifyDetached() parses it');
        self::assertNotSame(self::FIXTURE_PUBLIC_KEY_BASE64, SelfUpdate::PUBLIC_KEY_BASE64, 'production and fixture keys must stay distinct or the fixture legs prove nothing');

        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'ORIGINAL'));
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES)]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit, 'the production default (no key injection) is pinned state: an unsigned release is refused');
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
            $this->stageRelease('candy-code/v1.0.0', 'OLD-ONE'),
            $this->stageRelease('candy-code/v2.0.0', 'NEW-TWO', sigBody: self::FIXTURE_VALID_SIGNATURE),
        ];
        $this->releasesUrl($entries);
        $decoded = \json_decode((string) \file_get_contents($this->dir . '/srv/releases.json'), true);

        $selected = SelfUpdate::selectRelease($decoded);
        self::assertIsArray($selected);
        self::assertSame('v2.0.0', $selected['version']);
        self::assertStringEndsWith('candy-code-v2.0.0.minisig', $selected['sigUrl']);
    }

    // ------------------------------------------------------------------
    // PHA: self-update channels (stable/dev)
    // ------------------------------------------------------------------

    /**
     * A `dev-<shortsha>` current version rides the DEV channel by default —
     * exact-tag selection, commit-prefix freshness — and a NEWER stable
     * tag in the same list stays invisible to a dev install (the stable
     * ordering that would pick v9.9.9 must not leak across channels).
     */
    public function testTheCurrentDevStampDefaultsToTheDevChannel(): void
    {
        $target = '1a2b3c4def1a2b3c4def1a2b3c4def1a2b3c4def';
        $url = $this->releasesUrl([
            $this->stageRelease('candy-code/v9.9.9', 'STABLE-NEWER'),
            $this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: $target),
        ]);

        $current = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $url,
            $this->livePharPath(),
            'dev-1a2b3c4',
        );
        self::assertSame(NonInteractive::EXIT_OK, $current, 'a commit-prefix match IS current on the rolling train');
        $text = $this->errorText();
        self::assertStringContainsString('up to date', $text);
        self::assertStringContainsString('dev@' . $target, $text);
        self::assertStringNotContainsString('v9.9.9', $text, 'the stable train must not answer a dev install');
    }

    /**
     * Mismatch on the rolling train is forward, not behind: --check exits 1
     * naming the newer commit, and a real run swaps the bytes — the stable
     * channel's downgrade refusal is unreachable here by construction.
     */
    public function testTheDevChannelRollsToANewerCommit(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'DEV-INSTALLED'));
        $url = $this->releasesUrl([
            $this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: '1a2b3c4def56789012345678901234567890abcd'),
        ]);

        $check = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $url,
            $this->livePharPath(),
            'dev-9999999',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $check);
        $text = $this->errorText();
        self::assertStringContainsString('update available', $text);
        self::assertStringContainsString('dev@1a2b3c4def', $text);

        $this->resetErrorStream();

        // Keyless ('') injection: this test owns channel rolling, not crypto.
        $swapped = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $url,
            $this->livePharPath(),
            'dev-9999999',
            '',
        );
        self::assertSame(NonInteractive::EXIT_OK, $swapped, 'the rolling channel swaps an OLDER-looking dev stamp forward without a downgrade fight');
        self::assertSame('DEVNEW', \file_get_contents($this->livePharPath()));
        self::assertStringContainsString('dev-9999999 -> dev@1a2b3c4def', $this->errorText(), 'the installed report carries both commit identities');
    }

    /**
     * Fail-closed on every undecidable half: missing / branch-label /
     * too-short target_commitish, and a current stamp whose remainder is
     * not a commit ('dev-master', 'dev-'). The release is FOUND (so the
     * answer is cannot-compare, not no-dev-release) and NOTHING swaps.
     */
    public function testTheDevChannelRefusesEveryUndecidableComparison(): void
    {
        $valid = '1a2b3c4def56789012345678901234567890abcd';
        $cases = [
            'missing key' => [null, 'dev-1a2b3c4'],
            'branch label' => ['main', 'dev-1a2b3c4'],
            'short sha' => ['abc', 'dev-1a2b3c4'],
            'unparseable current' => [$valid, 'dev-master'],
            'empty-remainder current' => [$valid, 'dev-'],
        ];
        foreach ($cases as $name => [$commitish, $current]) {
            $this->resetErrorStream();
            self::assertNotFalse(\file_put_contents($this->livePharPath(), 'KEEP'));
            $url = $this->releasesUrl([
                $this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: $commitish),
            ]);
            $check = SelfUpdate::run(
                self::selfUpdateArgs(['self-update', '--check']),
                $this->fakeServerClient(),
                $url,
                $this->livePharPath(),
                $current,
            );
            self::assertSame(NonInteractive::EXIT_FAILURE, $check, $name . ': --check refuses to guess');
            self::assertStringContainsString('cannot compare', $this->errorText(), $name);

            $this->resetErrorStream();
            $swap = SelfUpdate::run(
                self::selfUpdateArgs(['self-update']),
                $this->fakeServerClient(),
                $url,
                $this->livePharPath(),
                $current,
                '',
            );
            self::assertSame(NonInteractive::EXIT_FAILURE, $swap, $name . ': a swap refuses the same way');
            self::assertStringContainsString('self-update can order', $this->errorText(), $name);
            self::assertSame('KEEP', \file_get_contents($this->livePharPath()), $name . ': the refusal never touches the live bytes');
        }
    }

    /**
     * A dev install whose list carries no qualifying `candy-code/dev` —
     * only stable tags, only a draft dev, a dev without the sidecar, or
     * the near-miss tag `candy-code/dev-master` — is refused by the
     * dev-shaped no-release message naming the tag, never silently handed
     * to the stable train.
     */
    public function testNoDevReleaseOnTheWaterRefusesTheDevChannel(): void
    {
        $lists = [
            'stable only' => [$this->stageRelease('candy-code/v9.9.9', 'STABLE')],
            'draft dev only' => [$this->stageRelease('candy-code/dev', 'D', draft: true, commitish: '1a2b3c4def')],
            'assetless dev only' => [$this->stageRelease('candy-code/dev', 'A', withSidecar: false, commitish: '1a2b3c4def')],
            'near-miss tag' => [$this->stageRelease('candy-code/dev-master', 'M', commitish: '1a2b3c4def')],
        ];
        foreach ($lists as $name => $entries) {
            $this->resetErrorStream();
            $exit = SelfUpdate::run(
                self::selfUpdateArgs(['self-update', '--check']),
                $this->fakeServerClient(),
                $this->releasesUrl($entries),
                $this->livePharPath(),
                'dev-1a2b3c4',
            );
            self::assertSame(NonInteractive::EXIT_FAILURE, $exit, $name);
            $text = $this->errorText();
            self::assertStringContainsString('no published dev release', $text, $name);
            self::assertStringContainsString(SelfUpdate::DEV_TAG, $text, $name . ': the refusal names the tag it wanted');
        }
    }

    /**
     * --channel overrides the classifier default in BOTH directions with no
     * cross-talk: a dev install forced onto stable runs the stable compare
     * (reporting v9.9.9, never the dev label); a stable install forced onto
     * dev runs the commit decision (reporting the dev label, never the
     * stable tag).
     */
    public function testTheChannelFlagOverridesTheDefaultBothDirections(): void
    {
        $target = '1a2b3c4def56789012345678901234567890abcd';
        $url = $this->releasesUrl([
            $this->stageRelease('candy-code/v9.9.9', 'STABLE-NEWER'),
            $this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: $target),
        ]);

        $forcedStable = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check', '--channel=stable']),
            $this->fakeServerClient(),
            $url,
            $this->livePharPath(),
            'dev-1a2b3c4',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $forcedStable);
        $text = $this->errorText();
        self::assertStringContainsString('cannot compare', $text);
        self::assertStringContainsString('v9.9.9', $text, 'stable selection ran: the tag, not the dev release, answered');
        self::assertStringNotContainsString('dev@', $text);

        $this->resetErrorStream();

        $forcedDev = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check', '--channel', 'dev']),
            $this->fakeServerClient(),
            $url,
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $forcedDev);
        $text = $this->errorText();
        self::assertStringContainsString('cannot compare', $text);
        self::assertStringContainsString('dev@' . $target, $text, 'dev selection ran: the commit, not the stable tag');
        self::assertStringNotContainsString('v9.9.9', $text);
    }

    /**
     * The usage family: a --channel that is neither train costs exit 2 and
     * the named refusal (mixed case is not a train either — the literals
     * are lowercase grammar); valid spellings compose with --check and
     * never cost 2.
     */
    public function testAnInvalidChannelIsAUsageErrorAndAValidOneComposes(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'KEEP'));
        $bogus = SelfUpdate::run(self::selfUpdateArgs(['self-update', '--channel=bogus']), null, null, $this->livePharPath(), 'v0.1.0');
        self::assertSame(NonInteractive::EXIT_CONFIG, $bogus);
        // failUsage writes the REAL stderr (no test seam by design — same
        // shape as every sibling usage door), so the words are pinned at
        // their source instead of by capture.
        self::assertStringContainsString('accepts stable or dev', Lang::t('cli.selfupdate.channel_invalid', ['value' => 'bogus']));
        self::assertStringContainsString('bogus', Lang::t('cli.selfupdate.channel_invalid', ['value' => 'bogus']), 'the refusal names the bad value');
        self::assertStringContainsString('[--channel=stable|dev]', Lang::t('cli.selfupdate.usage'), 'the usage line gained the flag');

        $this->resetErrorStream();
        $mixedCase = SelfUpdate::run(self::selfUpdateArgs(['self-update', '--channel=Stable']), null, null, $this->livePharPath(), 'v0.1.0');
        self::assertSame(NonInteractive::EXIT_CONFIG, $mixedCase, 'channel names are the lowercase literals');

        $this->resetErrorStream();
        $composed = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check', '--channel=stable']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $composed, 'a normal available answer, never a usage error');
        self::assertStringContainsString('update available', $this->errorText());
    }

    /**
     * The minisign rule is a fact about the KEY state, not the channel:
     * the pinned default refuses an unsigned dev release exactly as it
     * refuses an unsigned stable one (the dev branch falls into the SAME
     * sidecar/hash/signature legs).
     */
    public function testTheDevChannelKeepsThePinnedSignatureRule(): void
    {
        self::assertNotFalse(\file_put_contents($this->livePharPath(), 'KEEP'));
        $unsigned = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: '9999999def56789012345678901234567890abcd')]),
            $this->livePharPath(),
            'dev-1a2b3c4',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $unsigned);
        self::assertStringContainsString('carries no ' . SelfUpdate::SIGNATURE_ASSET, $this->errorText());
        self::assertSame('KEEP', \file_get_contents($this->livePharPath()));
    }

    /**
     * Selection is pure over the decoded list (the stable sibling always
     * was): exact-tag equality, prerelease allowance, draft skip, both-
     * assets requirement, and the lower-cased trimmed target read — a
     * missing target is STILL a found release (the refusal belongs to the
     * decision arm, never hidden as 'no release').
     */
    public function testSelectDevReleaseFiltersOnExactTagAndAssetsAlone(): void
    {
        $entries = [
            $this->stageRelease('candy-code/v1.0.0', 'STABLE'),
            $this->stageRelease('candy-code/devx', 'NEARTAG'),
            $this->stageRelease('candy-top/dev', 'OTHERAPP'),
            $this->stageRelease('candy-code/dev', 'DEVOK', prerelease: true, commitish: ' 1A2B3C4def '),
        ];
        $decoded = \json_decode(\json_encode($entries, \JSON_THROW_ON_ERROR), true);
        $selected = SelfUpdate::selectDevRelease($decoded);
        self::assertIsArray($selected);
        self::assertSame('dev', $selected['version']);
        self::assertSame('1a2b3c4def', $selected['commitish'], 'the target arrives lower-cased and trimmed for the decision arm');
        self::assertStringEndsWith('candy-code-dev.phar', $selected['pharUrl']);

        self::assertNull(SelfUpdate::selectDevRelease([]));
        self::assertNull(SelfUpdate::selectDevRelease([$this->stageRelease('candy-code/dev', 'NOSIDE', withSidecar: false)]));
        $noTarget = SelfUpdate::selectDevRelease([$this->stageRelease('candy-code/dev', 'DEVNOTARGET', prerelease: true)]);
        self::assertIsArray($noTarget, 'a missing target_commitish is still a found release — the refusal is the decision arm, not selection');
        self::assertSame('', $noTarget['commitish']);
    }

    /**
     * The end-to-end shape without version injection: a REAL minted dev-
     * stamped archive resolves through Help::versionString() (the widened
     * fromPharMetadata arm), classifies itself dev, and the freshness
     * decision runs on its stamped commit — DST-1's machinery now serving
     * the rolling train too.
     */
    public function testAStampedDevArchiveRidesTheDevChannelEndToEnd(): void
    {
        $stamped = self::mintStampedPhar('dev-1a2b3c4');
        Help::pretendHostArchiveForTesting($stamped);

        try {
            $exit = SelfUpdate::run(
                self::selfUpdateArgs(['self-update', '--check']),
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-code/dev', 'DEVNEW', prerelease: true, commitish: '1a2b3c4def5678')]),
                $this->livePharPath(),
            );
            self::assertSame(NonInteractive::EXIT_OK, $exit, 'the stamp read from the archive is current against the dev release');
            $text = $this->errorText();
            self::assertStringContainsString('dev-1a2b3c4', $text, 'the stamped commit, not a frozen dev-master guess, is what answered');
            self::assertStringNotContainsString('dev-master', $text);
        } finally {
            Help::pretendHostArchiveForTesting(null);
            self::discardStampedPhar($stamped);
        }
    }

    /**
     * DST-3-P2: rename() publishes the temp's mode and copy() takes the
     * umask — both halves of the swap must carry the LIVE file's mode, and
     * a rollback of a legacy `.old` (one made before this existed) re-asserts
     * the mode the phar had when the verb started, not whatever the kept
     * copy drifted to.
     */
    public function testTheSwapAndTheRollbackBothPreserveTheExecBit(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('chmod carries no executable-bit truth on NT; the Windows swap leg dies at the documented rename refusal.');
        }

        foreach ([0755, 0700] as $mode) {
            $phar = $this->livePharPath();
            $old = $phar . '.old';
            $label = 'mode ' . \decoct($mode) . ': ';
            self::assertNotFalse(\file_put_contents($phar, 'ORIGINAL'));
            self::assertNotFalse(\chmod($phar, $mode));
            $this->resetErrorStream();

            $installed = SelfUpdate::run(
                self::selfUpdateArgs(['self-update']),
                $this->fakeServerClient(),
                $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
                $phar,
                'v0.1.0',
                '',
            );
            self::assertSame(NonInteractive::EXIT_OK, $installed, $label . 'the swap runs');
            self::assertSame('UPDATED-PHAR-BYTES', \file_get_contents($phar), $label . 'the new bytes landed');
            self::assertSame($mode, \fileperms($phar) & 0777, $label . 'rename carried the live mode, not the temp umask');
            self::assertFileExists($old);
            self::assertSame($mode, \fileperms($old) & 0777, $label . 'the kept copy carries the live mode, not the copy umask');

            // The legacy shape: a pre-preservation `.old` sits at the umask.
            // The rollback must hand the ORIGINAL bytes back AT THE CAPTURED
            // MODE, because --rollback renames .old over the phar and the
            // rename would otherwise publish the kept copy's mode.
            self::assertNotFalse(\chmod($old, 0600));
            $this->resetErrorStream();
            $restored = SelfUpdate::run(self::selfUpdateArgs(['self-update', '--rollback']), null, null, $phar);
            self::assertSame(NonInteractive::EXIT_OK, $restored, $label . 'the rollback runs');
            self::assertSame('ORIGINAL', \file_get_contents($phar), $label . 'the prior bytes came back');
            self::assertSame($mode, \fileperms($phar) & 0777, $label . 'the rollback re-asserted the captured mode over the legacy .old mode');
        }
    }

    /**
     * DST-3-P4 + DST-3-P1: the client runs with http_errors off, so a 404 is
     * a plain response — every download leg must judge status itself, name
     * it in the refusal, and leave the live phar byte-identical with no
     * half-written sink behind. The shutdown sweep is pinned structurally on
     * the real source (its registration must sit between the temp's mint and
     * the rename that consumes it — that window is exactly the stranding
     * window).
     */
    public function testEveryDownloadLegJudgesItsHttpStatus(): void
    {
        $phar = $this->livePharPath();

        // Leg 1: the sidecar 404s — the verb stops before a single phar byte
        // moves, so no temp is even minted.
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(['/candy-code-v9.9.9.sha256' => 404]),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $phar,
            'v0.1.0',
            '',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit, 'a 404 sidecar is a refusal, not an empty body');
        self::assertStringContainsString(SelfUpdate::SIDECAR_ASSET . ': HTTP 404', $this->errorText());
        self::assertSame('INSTALLED-PHAR-BYTES', \file_get_contents($phar), 'the live phar never moved');

        // Leg 2: the phar 404s after the sidecar passed — the sink receives
        // the error document, the leg judges status, and the half-file goes.
        $this->resetErrorStream();
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(['/candy-code-v9.9.9.phar' => 404]),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $phar,
            'v0.1.0',
            '',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(SelfUpdate::PHAR_ASSET . ': HTTP 404', $this->errorText());
        self::assertSame([], \glob($phar . '.tmp-' . \chr(42)), 'the sink that the 404 half-filled is gone');
        self::assertSame('INSTALLED-PHAR-BYTES', \file_get_contents($phar));
        self::assertFileDoesNotExist($phar . '.old');

        // Leg 3: the signature 404s in PINNED state — the phar bytes are
        // hash-valid and sitting in the temp, and the refusal must still
        // sweep the temp and leave the install untouched.
        $this->resetErrorStream();
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(['/candy-code-v9.9.9.minisig' => 404]),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', self::SIGNED_PAYLOAD_BYTES, sigBody: self::FIXTURE_VALID_SIGNATURE)]),
            $phar,
            'v0.1.0',
            self::FIXTURE_PUBLIC_KEY_BASE64,
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringContainsString(SelfUpdate::SIGNATURE_ASSET . ': HTTP 404', $this->errorText());
        self::assertSame([], \glob($phar . '.tmp-' . \chr(42)));
        self::assertSame('INSTALLED-PHAR-BYTES', \file_get_contents($phar));

        // Structural pin of the sweep hook: mint < hook registration < rename.
        $src = (string) \file_get_contents((new \ReflectionClass(SelfUpdate::class))->getFileName());
        $mint = \strpos($src, "\$temp = \$phar . '.tmp-'");
        $hook = \strpos($src, 'register_shutdown_function');
        $rename = \strpos($src, 'if (!\\rename($temp, $phar))');
        self::assertIsInt($mint, 'the mint line the pin measures from exists');
        self::assertIsInt($rename, 'the rename line the pin measures to exists');
        self::assertIsInt($hook, 'a shutdown sweep is registered at all');
        self::assertGreaterThan($mint, $hook, 'the sweep can only forget a temp that was already minted');
        self::assertLessThan($rename, $hook, 'the sweep is armed before the stranding window opens');
    }

    /**
     * DST-3-P3, E646: the shipped client carries the low-speed stall pair on
     * the curl transport and the connect/read ceilings, and NEVER a total
     * request clock — a big phar over a slow link is legitimate work.
     */
    public function testTheProductionClientCarriesTheStallGuardsAndNoBlanketClock(): void
    {
        $client = (new \ReflectionMethod(SelfUpdate::class, 'httpClient'))->invoke(null);
        self::assertInstanceOf(Client::class, $client);
        $config = (new \ReflectionProperty(Client::class, 'config'))->getValue($client);
        self::assertIsArray($config);

        self::assertSame(5.0, $config['connect_timeout']);
        self::assertSame(60.0, $config['read_timeout']);
        self::assertFalse($config['http_errors']);
        self::assertArrayNotHasKey('timeout', $config, 'E646: no blanket total-request clock on the shipped client');

        if (\defined('CURLOPT_LOW_SPEED_LIMIT') && \defined('CURLOPT_LOW_SPEED_TIME')) {
            $curl = $config['curl'] ?? [];
            self::assertSame(1024, $curl[\CURLOPT_LOW_SPEED_LIMIT] ?? null, 'the curl transport aborts a stalled bulk transfer');
            self::assertSame(60, $curl[\CURLOPT_LOW_SPEED_TIME] ?? null, 'after a minute under the low-speed floor');
        } else {
            self::assertArrayNotHasKey('curl', $config, 'a curl-less runtime keeps the StreamHandler shape');
            self::assertArrayNotHasKey('curl', $config, 'production sets the key only under the \\defined guard');
        }
    }

    /**
     * DST-3-P5: selection walks `Link: rel="next"` pages (the newest
     * candy-code tag can ride page two of a busy repo) but the walk is
     * capped — page six of a runaway chain is never fetched.
     */
    public function testSelectionFollowsPagination(): void
    {
        // A: the candy-code release lives on page two; the old one-page
        // lookup answers "no release", the walk finds v9.9.9.
        $foreign = $this->stageRelease('candy-sql/v99.0.0', 'OTHER-LIB');
        $wanted = $this->stageRelease('candy-code/v9.9.9', 'NEW');
        $page1 = $this->releasesUrl([$foreign], 'releases-page1.json');
        $this->releasesUrl([$wanted], 'releases-page2.json');
        $log = [];
        $client = $this->fakeServerClient([], ['/releases-page1.json' => '<' . self::SERVER_ORIGIN . '/releases-page2.json>; rel="next"'], $log);
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $client,
            $page1,
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit, '--check finding an update exits 1 by design');
        self::assertStringContainsString('v9.9.9', $this->errorText(), 'the paged lookup found the release the one-page list would have missed');
        self::assertSame(['/releases-page1.json', '/releases-page2.json'], $log);

        // B: a chain with no end — the cap stops the walk at five pages. The
        // candy-code tag rides page six, so a walk that ignored the cap would
        // find it and report v9.9.9 instead of refusing.
        $this->resetErrorStream();
        $links = [];
        for ($page = 1; $page <= 7; $page++) {
            $entries = [$this->stageRelease('candy-sql/v' . $page . '.0.0', 'OTHER-' . $page)];
            if ($page === 6) {
                $entries[] = $this->stageRelease('candy-code/v9.9.9', 'PAST-THE-CAP');
            }
            $this->releasesUrl($entries, 'paged-' . $page . '.json');
            $links['/paged-' . $page . '.json'] = '<' . self::SERVER_ORIGIN . '/paged-' . ($page + 1) . '.json>; rel="next"';
        }
        $log = [];
        $capped = $this->fakeServerClient([], $links, $log);
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $capped,
            self::SERVER_ORIGIN . '/paged-1.json',
            $this->livePharPath(),
            'v0.1.0',
        );
        self::assertSame(NonInteractive::EXIT_FAILURE, $exit);
        self::assertStringNotContainsString('v9.9.9', $this->errorText(), 'a runaway pagination chain cannot drag the verb page after page');
        self::assertCount(5, $log, 'exactly five pages are fetched, no more');
        self::assertSame('/paged-1.json', $log[0]);
        self::assertSame('/paged-5.json', $log[4]);
    }

    /**
     * DST-3-P5's refusal branch: the composer-mode answer fits the install.
     * A consuming project is pointed at its dependency update; a clone whose
     * OWN package is the Composer root — where that command updates the
     * dependencies, not the package — is pointed at the pull-and-rebuild.
     */
    public function testTheComposerRefusalNamesTheHonestCommandForEachInstallShape(): void
    {
        $foreign = SelfUpdate::composerRefusal('acme/consuming-tool');
        self::assertStringContainsString('Composer', $foreign);
        self::assertStringContainsString(SelfUpdate::COMPOSER_COMMAND, $foreign);
        self::assertStringContainsString('composer global update sugarcraft/candy-code', $foreign);
        self::assertStringNotContainsString('git pull', $foreign);

        $root = SelfUpdate::composerRefusal('sugarcraft/candy-code');
        self::assertStringContainsString('Composer', $root);
        self::assertStringContainsString(SelfUpdate::PULL_AND_INSTALL_COMMAND, $root);
        self::assertStringNotContainsString('composer update', $root, 'the root answer never tells a package to update itself through its own dependency command');

        self::assertNotSame($foreign, $root, 'one string cannot be honest for both install shapes');
    }
}

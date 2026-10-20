<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

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
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\SelfUpdate;
use SugarCraft\Code\Support\Version;

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
 * the $pharPath seam.
 */
final class SelfUpdateTest extends TestCase
{
    private const SERVER_ORIGIN = 'http://fake-candy-code-server.invalid';

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
        return $this->dir . '/live/candy-code.phar';
    }

    /**
     * Stage one release on the fake server and return its JSON entry. The
     * sidecar defaults to the true digest of the staged phar in sha256sum's
     * own '<hex>  <name>' spelling.
     *
     * @return array<array-key, mixed>
     */
    private function stageRelease(string $tag, string $pharBytes, ?string $sidecarBody = null, bool $withSidecar = true, bool $draft = false, bool $prerelease = false): array
    {
        $slug = \strtr($tag, ['/' => '-']);
        $assets = [[
            'name' => SelfUpdate::PHAR_ASSET,
            'browser_download_url' => self::SERVER_ORIGIN . '/' . $slug . '.phar',
        ]];
        self::assertNotFalse(\file_put_contents($this->dir . '/srv/' . $slug . '.phar', $pharBytes));
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
        self::assertStringContainsString('composer update sugarcraft/candy-code', $text);
        self::assertStringContainsString('composer global update sugarcraft/candy-code', $text);
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
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update', '--check']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'NEW')]),
            $this->livePharPath(),
            'dev-master',
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
        $exit = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
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
        $installed = SelfUpdate::run(
            self::selfUpdateArgs(['self-update']),
            $this->fakeServerClient(),
            $this->releasesUrl([$this->stageRelease('candy-code/v9.9.9', 'UPDATED-PHAR-BYTES')]),
            $this->livePharPath(),
            'v0.1.0',
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
}

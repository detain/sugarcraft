<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\ExportCommand;
use SugarCraft\Code\Memory\SecretRedactor;
use SugarCraft\Code\Message;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Plan W4-B: `/export [format] [file|--path <file>]` writes the transcript
 * to a LOCAL file with its RAW bytes — the SEC-4 ruling's "local export
 * keeps raw bytes" half. The family pins below carry that meaning: the
 * export file keeps a secret that the /share file beside it redacts, the
 * plain file is jailed to the project or the ~/.candy-code shelf while
 * --path is the named escape hatch, the default door writes plain text, and
 * only plain text passes the display sweep that strips private-use and
 * invisible-formatting bytes without touching secrets.
 */
final class ExportCommandTest extends TestCase
{
    use HomeSandboxTrait;

    /** An OpenAI-shaped key: 20 more [A-Za-z0-9_-] chars after the prefix. */
    private const SECRET = 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWX';

    /** Image-marker and zone-sentinel bytes (PUA) plus a bidi override, planted in one user row. */
    private const MARKERS = "\u{E000}keep-me\u{E001}\u{E02A}\u{202E}";

    private string $tempDir = '';

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/export_' . bin2hex(random_bytes(6));
        $this->root = $this->tempDir . '/project';
        mkdir($this->root, 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeFixtureTree($this->tempDir);

        parent::tearDown();
    }

    public function testABareExportWritesARawTextFileUnderTheHomeExportsDirectory(): void
    {
        $reply = $this->slashExport('/export');

        $this->assertSame(1, preg_match('/to `([^`]+)`/', $reply->content, $written), 'the reply names the written file');
        $path = $written[1];
        $exports = $this->tempDir . '/home/.candy-code/exports';

        $this->assertSame($exports, \dirname($path));
        $this->assertSame(1, preg_match('/^transcript-sess-42-\d{8}T\d{6}Z\.txt$/', basename($path)), 'the plan default door is plain text, and the transcript- prefix keeps /share names clear of this shelf');
        $this->assertSame(0700, fileperms($exports) & 0777);
        $this->assertSame(0600, fileperms($path) & 0777, 'a raw transcript can hold secrets');

        $text = (string) file_get_contents($path);
        $this->assertStringContainsString('please fix the bug', $text);
        $this->assertStringContainsString('done, see the diff', $text);
        $this->assertStringNotContainsString('a ui-only notice', $text, 'app chrome is not exported');
        $this->assertStringContainsString('not secret-redacted', $reply->content, 'the reply says the bytes are raw');
    }

    public function testTheRawFileKeepsWhatShareRedactsBesideIt(): void
    {
        $secret = $this->slashExport('/export out/secret-export.md');
        $this->assertStringContainsString("to `{$this->root}/out/secret-export.md`", $secret->content);
        $exported = (string) file_get_contents($this->root . '/out/secret-export.md');

        $share = $this->slashExport('/share md out/secret-share.md');
        $this->assertStringContainsString("to `{$this->root}/out/secret-share.md`", $share->content);
        $shared = (string) file_get_contents($this->root . '/out/secret-share.md');

        $this->assertStringContainsString(self::SECRET, $exported, 'the ruling: local export keeps raw bytes');
        $this->assertStringNotContainsString(self::SECRET, $shared, 'the publish-family file is redacted');
        $this->assertStringContainsString(SecretRedactor::MARKER, $shared, 'and visibly so');
    }

    /**
     * The text-only display sweep (plan: "through the maskImageMarkers/
     * Sanitize pipeline"): private-use marker bytes and the bare bidi
     * override do not belong in a .txt a user will cat, but this is hygiene,
     * not redaction — the secret survives, and the structured formats are
     * written byte-exact because their consumers parse them.
     */
    public function testPlainTextStripsInvisibleBytesYetKeepsSecretsAndOtherFormatsStayByteExact(): void
    {
        $reply = $this->slashExport('/export text out/swept.txt');
        $swept = (string) file_get_contents($this->root . '/out/swept.txt');

        $this->assertStringContainsString('keep-me', $swept, 'the zone content between sentinels is the transcript, it stays');
        $this->assertStringNotContainsString("\u{E000}", $swept, 'the image/zone marker bytes are stripped');
        $this->assertStringNotContainsString("\u{E001}", $swept);
        $this->assertStringNotContainsString("\u{E02A}", $swept);
        $this->assertStringNotContainsString("\u{202E}", $swept, 'no raw bidi override ships in a text export');
        $this->assertStringContainsString('<U+202E>', $swept, 'it is replaced by its visible name, not deleted in silence');
        $this->assertStringContainsString(self::SECRET, $swept, 'sweeping is not redacting');

        $markdown = $this->slashExport('/export md out/bytes.md');
        $bytes = (string) file_get_contents($this->root . '/out/bytes.md');
        $this->assertStringContainsString("\u{E000}", $bytes, 'markdown keeps every byte — the reply naming it proves the door ran: ' . $markdown->content);
    }

    public function testJsonIsTheSameRowsAsRawWireData(): void
    {
        $reply = $this->slashExport('/export json');
        preg_match('/to `([^`]+)`/', $reply->content, $written);

        $this->assertStringEndsWith('.json', $written[1]);
        $rows = json_decode((string) file_get_contents($written[1]), true);
        $this->assertIsArray($rows);
        $this->assertStringContainsString(self::SECRET, (string) file_get_contents($written[1]), 'raw reaches every format, not just the default text');
    }

    public function testAPathInsideTheProjectIsWrittenAndItsExtensionPicksTheFormat(): void
    {
        $reply = $this->slashExport('/export notes.html');

        $expected = realpath($this->root) . '/notes.html';
        $this->assertStringContainsString("to `{$expected}`", $reply->content);
        $this->assertStringContainsString('as html', $reply->content);
        $this->assertFileExists($expected);
        $this->assertStringStartsWith('<!DOCTYPE html>', (string) file_get_contents($expected));
    }

    public function testANameWithoutAnExtensionReceivesTheFormatSuffix(): void
    {
        $reply = $this->slashExport('/export text out/plain');

        $this->assertStringContainsString("to `{$this->root}/out/plain.txt`", $reply->content);
        $this->assertFileExists($this->root . '/out/plain.txt');
    }

    public function testAnExistingDirectoryReceivesTheDefaultFileName(): void
    {
        mkdir($this->root . '/out');

        $reply = $this->slashExport('/export md out');

        $this->assertSame(1, preg_match('/to `([^`]+)`/', $reply->content, $written));
        $this->assertSame(realpath($this->root . '/out'), \dirname($written[1]));
        $this->assertStringEndsWith('.md', $written[1]);
    }

    /**
     * The chosen overwrite semantics (W4-B): an explicit target REPLACES,
     * atomically — no suffixed copies, and the reply names the same file.
     */
    public function testAnExplicitTargetIsOverwrittenRatherThanDuplicated(): void
    {
        $this->slashExport('/export out/two.md');
        $reply = $this->slashExport('/export out/two.md');

        $path = $this->root . '/out/two.md';
        $this->assertStringContainsString("to `{$path}`", $reply->content);
        $this->assertSame([$path], glob($this->root . '/out/' . chr(42)), 'exactly one file: the second export replaced the first');
    }

    /**
     * A plain name may miss the project jail only onto the tool's own shelf;
     * anywhere else the door closes and names the escape hatch.
     */
    public function testAPositionalOnTheHomeShelfIsWrittenAndAnywhereElseHomeIsRefused(): void
    {
        $reply = $this->slashExport('/export ~/.candy-code/notes.md');

        $shelf = $this->tempDir . '/home/.candy-code/notes.md';
        $this->assertStringContainsString('as markdown', $reply->content);
        $this->assertFileExists($shelf);

        $next = $this->dispatch('/export ~/escaped.txt');
        $refusal = $next->history[\count($next->history) - 1];
        $this->assertSame(Role::System, $refusal->role);
        $this->assertStringContainsString('must stay inside the project root', $refusal->content);
        $this->assertFileDoesNotExist($this->tempDir . '/home/escaped.txt');
    }

    /**
     * --path is the named escape hatch: the user typed the door, so the jail
     * steps aside (still 0600, still atomic) for absolute and project-anchored
     * spellings alike.
     */
    public function testThePathFlagWritesAnywhereItsNameSays(): void
    {
        $outside = $this->tempDir . '/anywhere/deep/notes.txt';
        $reply = $this->slashExport('/export text --path ' . $outside);

        $this->assertStringContainsString("to `{$outside}`", $reply->content);
        $this->assertFileExists($outside);
        $this->assertStringContainsString(self::SECRET, (string) file_get_contents($outside));

        $inline = $this->slashExport('/export --path=out/inline.md');
        $this->assertStringContainsString("to `{$this->root}/out/inline.md`", $inline->content, 'the =-spelling, relative to the project, extension still picks the format');
        $this->assertFileExists($this->root . '/out/inline.md');
    }

    public function testNamingTheFileBothWaysAtOnceIsRefused(): void
    {
        $next = $this->dispatch('/export out/a.md --path out/b.md');
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role);
        $this->assertStringContainsString('Name the file once', $reply->content);
        $this->assertFileDoesNotExist($this->root . '/out/a.md');
        $this->assertFileDoesNotExist($this->root . '/out/b.md');
    }

    public function testAnUnknownFlagIsRefusedRatherThanSilentlyIgnored(): void
    {
        $next = $this->dispatch('/export --force');
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role);
        $this->assertStringContainsString('Unexpected argument: --force', $reply->content);
    }

    public function testPathWithoutAFileIsRefused(): void
    {
        $next = $this->dispatch('/export text --path');
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role);
        $this->assertStringContainsString('Expected a file name after --path.', $reply->content);
    }

    /**
     * @dataProvider escapingPaths
     */
    public function testAnExportPathOutsideTheProjectIsRefusedAndNothingIsWritten(string $path): void
    {
        $next = $this->dispatch('/export ' . $path);
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role, 'a refusal is a notice, not a model reply');
        $this->assertStringContainsString('must stay inside the project root', $reply->content);
        $this->assertFileDoesNotExist($this->tempDir . '/escaped.md');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingPaths(): array
    {
        return [
            'dot-dot' => ['../escaped.md'],
            'absolute' => ['/tmp/../' . 'escaped-export-' . 'probe.md'],
        ];
    }

    public function testASessionWithNothingVisibleRefusesRatherThanWritingAnEmptyFile(): void
    {
        $chat = new Chat(
            history: [Message::notice('a ui-only notice')],
            inputBuf: '',
            backend: new EchoBackend(),
            currentSessionId: 'sess-42',
            projectRoot: $this->root,
        );

        ob_start();
        $exit = (new ExportCommand($this->tempDir . '/shelf'))->execute($chat, []);
        $output = (string) ob_get_clean();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Nothing to export', $output);
        $this->assertFileDoesNotExist($this->tempDir . '/shelf');
    }

    public function testAMistypedFormatIsRefusedWithTheUsageLine(): void
    {
        $next = $this->dispatch('/export xlsx');
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role);
        $this->assertStringContainsString("Invalid format 'xlsx'", $reply->content);
        $this->assertStringContainsString('Usage: /export', $reply->content, 'the refusal shows the door');
    }

    public function testTooManyArgumentsAreRefused(): void
    {
        $next = $this->dispatch('/export md out/a.md out/b.md');
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role);
        $this->assertStringContainsString('Too many arguments.', $reply->content);
        $this->assertFileDoesNotExist($this->root . '/out/a.md');
    }

    public function testFillablePaletteTokensLandAndStalePaletteNamesDegradeVisibly(): void
    {
        $reply = $this->slashExport('/export out/notes-[seed]-[date].md');

        $date = date('Y-m-d');
        $this->assertStringContainsString("to `{$this->root}/out/notes-[seed]-{$date}.md`", $reply->content, 'date fills in MediaStore format, [seed] has no export value and stays literal');
        $this->assertFileExists($this->root . '/out/notes-[seed]-' . $date . '.md');
        $this->assertStringContainsString('Left in the name as typed: [seed]', $reply->content, 'and the reply says so');
    }

    public function testTheCommandSeamWritesToAnExplicitExportsDirectory(): void
    {
        $dir = $this->tempDir . '/elsewhere';

        ob_start();
        $exit = (new ExportCommand($dir))->execute($this->chat(''), []);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("to `{$dir}/transcript-sess-42-", $output);
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    public function testTheRowRegistersAndThePaletteActionRoundTrips(): void
    {
        $this->assertContains('export', array_map(static fn($spec): string => $spec->name, CommandRegistry::slashCommands()));
        $this->assertSame('export', PaletteAction::ExportTranscript->spec()->name, 'dispatchPaletteCommand routes this row generically');
        $this->assertSame(PaletteAction::ExportTranscript, CommandRegistry::forPaletteAction(PaletteAction::ExportTranscript)?->paletteAction);
    }

    public function testAReadOnlySessionMayStillExport(): void
    {
        [$next] = $this->chat('/export out/ro.md', readOnly: true)->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(Chat::class, $next);
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::Assistant, $reply->role, 'a file export writes no session bytes — /share runs here, so /export does too');
        $this->assertFileExists($this->root . '/out/ro.md');
    }

    /**
     * The no-tty door (plan W4-B parity with /share): a headless SessionHost
     * runs the same ExportHostCommand body the TUI handler delegates to and
     * writes the same file — and the exchange is answered as handled, not
     * client-only.
     */
    public function testAHeadlessSessionExportsThroughTheSameHostCommand(): void
    {
        $host = $this->hostSession();

        $ticket = $host->submit('/export text out/headless.txt');
        $this->assertSame(TurnTicket::HANDLED, $ticket->admitted, 'export is a headless door, not a screen-only one');

        $written = $this->root . '/out/headless.txt';
        $this->assertFileExists($written, 'the same file the TUI would have written');
        $this->assertStringContainsString('hello raw', (string) file_get_contents($written));

        $rows = $host->history();
        $last = $rows[\count($rows) - 1];
        $this->assertStringContainsString("to `{$written}`", $last->content);

        $host->release();
    }

    public function testAHeadlessBareExportNeedsNoTtyForItsDefaultName(): void
    {
        $host = $this->hostSession();
        $ticket = $host->submit('/export');

        $this->assertSame(TurnTicket::HANDLED, $ticket->admitted);
        $rows = $host->history();
        $this->assertStringContainsString('as text', $rows[\count($rows) - 1]->content, 'the default text door answers headless too');

        $host->release();
    }

    private function hostSession(): \SugarCraft\Code\Host\SessionHost
    {
        $store = new EnhancedSessionStore($this->tempDir . '/session.db');
        $store->createSession('s', 'p', 'm');

        return \SugarCraft\Code\Host\SessionHost::new(
            's',
            \SugarCraft\Code\Host\WorkspaceContext::new(
                root: $this->root,
                sessionStore: $store,
                backend: new EchoBackend(),
            ),
            history: [
                Message::user('hello raw ' . self::SECRET),
                new Message(Role::Assistant, 'done', time()),
            ],
            customCommands: [],
        );
    }

    private function slashExport(string $line): Message
    {
        $next = $this->dispatch($line);
        $reply = $next->history[\count($next->history) - 1];
        $this->assertSame(Role::Assistant, $reply->role, 'a successful export replies as a transcript row: ' . $reply->content);

        return $reply;
    }

    private function dispatch(string $line): Chat
    {
        [$next] = $this->chat($line)->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    private function chat(string $draft, bool $readOnly = false): Chat
    {
        return new Chat(
            history: [
                Message::system('You are a coding agent.'),
                Message::user('please fix the bug <script>alert(1)</script> and keep ' . self::SECRET . ' working ' . self::MARKERS),
                new Message(Role::Assistant, 'done, see the diff', time()),
                Message::notice('a ui-only notice'),
            ],
            inputBuf: $draft,
            backend: new EchoBackend(),
            currentSessionId: 'sess-42',
            projectRoot: $this->root,
            readOnlySession: $readOnly,
        );
    }

    private function removeFixtureTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

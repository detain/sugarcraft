<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Cli\Subcommands;
use SugarCraft\Code\Host\TitleService;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Session\TitleSource;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * M13 — a session name is untrusted text on its way OUT even when it arrived
 * as a user rename: one stored with an embedded OSC/CSI/newline re-fired on
 * every read-only `session list`/`show` invocation, forging terminal state
 * (window title, screen clears, extra table rows) long after the poison was
 * written. The rename door now runs the canonical {@see TitleService::sanitizeTitle()}
 * (the same vocabulary the auto-titler and the TUI rename use) before storing,
 * the label emits through `Sanitize::visibleControls()` so even a row stored
 * by an older build reads as caret notation instead of executing, and the
 * headless transcript dump sheds control bytes the same way. The JSON
 * surfaces are deliberately untouched — json escaping already neutralises
 * control characters there.
 */
final class SessionTitleInjectionTest extends TestCase
{
    use HomeSandboxTrait;

    /** A title carrying an OSC-0 window-title set, a CSI erase-screen, and a newline forging a second row. */
    private const POISON = "a\x1b]0;PWNED\x07b\x1b[2Jc\nd";

    private string $home;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->home = (string) realpath(sys_get_temp_dir()) . '/m13-' . uniqid((string) getmypid(), true);
        self::assertTrue(mkdir($this->home . '/.candy-code', 0700, true));
        chmod($this->home, 0700);
        $this->useHomeSandbox($this->home);

        $this->store = new EnhancedSessionStore($this->home . '/.candy-code/session.db');
        $this->store->createSession('sess-main-0001', 'openai', 'gpt-4o', null, 'login work');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeTree($this->home);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function dispatch(array $args): array
    {
        ob_start();
        $rc = Subcommands::dispatch(ArgvParser::parse(['candy-code', ...$args]));

        return [$rc, (string) ob_get_clean()];
    }

    public function testTheRenameDoorSanitisesTheTitleBeforeStoringIt(): void
    {
        [$rc, $stdout] = $this->dispatch(['session', 'rename', 'sess-main-0001', self::POISON]);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);

        $stored = $this->store->session('sess-main-0001')['name'] ?? null;
        self::assertIsString($stored);
        self::assertStringNotContainsString("\x1b", $stored, 'the stored name must not carry a raw escape');
        self::assertStringNotContainsString("\n", $stored, 'the stored name must be one line');
        // The visible words survive; the injected bytes read as caret notation of their
        // own text, and the tail after the embedded newline is gone with the line break.
        self::assertStringStartsWith('a', $stored);

        // The confirmation echo carries no raw byte either (it prints the stored title).
        self::assertStringNotContainsString("\x1b", $stdout);
    }

    public function testTheRenameDoorCapsTheTitleToTheHostsLengthLaw(): void
    {
        [$rc] = $this->dispatch(['session', 'rename', 'sess-main-0001', str_repeat('x', 300)]);
        self::assertSame(NonInteractive::EXIT_OK, $rc);

        $stored = (string) ($this->store->session('sess-main-0001')['name'] ?? '');
        self::assertSame(TitleService::TITLE_MAX_CHARS, mb_strlen($stored));
    }

    public function testATitleThatSanitisesToNothingIsRefusedAndNotStored(): void
    {
        $before = $this->store->session('sess-main-0001')['name'] ?? null;
        [$rc, $stdout] = $this->dispatch(['session', 'rename', 'sess-main-0001', '--output-format', 'json', "\x1b\x07"]);
        self::assertSame(NonInteractive::EXIT_CONFIG, $rc, $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertSame('usage', $decoded['error']['type'] ?? null, $stdout);
        self::assertSame($before, $this->store->session('sess-main-0001')['name'] ?? null);
    }

    public function testALegacyPoisonedRowReadsSafelyInTheTable(): void
    {
        // Seed exactly what an older build stored: the raw argv-joined poison.
        self::assertTrue($this->store->renameSession('sess-main-0001', self::POISON, TitleSource::User));

        [$rc, $stdout] = $this->dispatch(['session', 'list']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        self::assertStringNotContainsString("\x1b", $stdout, 'the table must render controls visibly, not replay them');
        self::assertStringNotContainsString("\x07", $stdout);
        // The poisoned label must not be able to forge extra table rows: the
        // newline reads as ^J inside the single row.
        self::assertStringContainsString('^[[2J', $stdout);
        self::assertStringContainsString('^J', $stdout);
        $rows = array_values(array_filter(explode("\n", $stdout), static fn (string $l): bool => $l !== ''));
        // exactly one line for the one session — a raw \n in the name would have made more.
        self::assertCount(1, $rows, $stdout);
    }

    public function testTheShowHeaderSanitisesItsLabel(): void
    {
        self::assertTrue($this->store->renameSession('sess-main-0001', self::POISON, TitleSource::User));

        [$rc, $stdout] = $this->dispatch(['session', 'show', 'sess-main-0001']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        self::assertStringNotContainsString("\x1b", $stdout);
        $header = explode("\n", $stdout)[0];
        self::assertStringStartsWith('# a', $header);
        self::assertStringContainsString('^[', $header, 'the header label renders its escapes visibly');
    }

    public function testTheTranscriptLegShowsControlBytesInsteadOfReplayingThem(): void
    {
        // A stored transcript carrying SGR bytes (what a poisoned tool result leaves behind).
        $this->store->saveTranscript('sess-main-0001', [Message::user("run \x1b[31mred\x1b[0m now")]);

        [$rc, $stdout] = $this->dispatch(['session', 'show', 'sess-main-0001']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        self::assertStringNotContainsString("\x1b[", $stdout);
        self::assertStringContainsString('^[[31m', $stdout, 'the escape must read as caret notation');
    }

    public function testTheJsonSurfaceStaysVerbatimAndProperlyEscaped(): void
    {
        $this->store->saveTranscript('sess-main-0001', [Message::user("run \x1b[31mred\x1b[0m now")]);

        [$rc, $stdout] = $this->dispatch(['session', 'show', 'sess-main-0001', '--output-format', 'json']);
        self::assertSame(NonInteractive::EXIT_OK, $rc, $stdout);
        // The wire text never carries the raw byte, but the decoded document does:
        // JSON consumers must keep receiving the exact stored transcript.
        self::assertStringNotContainsString("\x1b", $stdout);
        $decoded = json_decode(trim($stdout), true);
        self::assertIsArray($decoded);
        $flat = (string) json_encode($decoded['result']['messages'] ?? []);
        self::assertStringContainsString('\u001b[31m', $flat);
    }
}

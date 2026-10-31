<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\HooksHostCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookLog;
use SugarCraft\Code\Hooks\HookLogEntry;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Message;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-EXT-6.b: `/hooks log [n]` — the reader half of the hook log.
 *
 * The pins here are deliberately about the ROUTING and the RENDER, not about
 * the file format (HookLogTest owns the wire, HookLogConcurrentAppendTest the
 * append): a bad `n` refuses loudly instead of silently clamping, an unknown
 * first word is answered rather than ignored (the pre-C-EXT-6.b shape — the
 * host command read no arguments at all), rows carry through the table, the
 * session filter filters, and an unreadable file announces itself instead of
 * rendering as "no entries" — the last is the one that would otherwise teach
 * the user their hooks did not run when the truth is nobody looked.
 *
 * The default log leaf is pinned to a per-test path in setUp/tearDown for the
 * same reason tests/bootstrap.php pins it suite-wide: an unpinned default
 * resolves to the real `~/.candy-code/hooks-log.jsonl`, and a reader test that
 * counted rows would then be reading the machine, not the fixture.
 */
final class HooksLogCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir = '';

    private string $logFile = '';

    private ?string $suitePin = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suitePin = HookLog::defaultLogFilePin();
        $this->tempDir = sys_get_temp_dir() . '/candy-code-hookslog-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);
        $this->logFile = $this->tempDir . '/hooks-log.jsonl';
        HookLog::pinDefaultLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        HookLog::pinDefaultLogFile($this->suitePin);
        $this->restoreHomeSandbox();
        if ($this->tempDir !== '') {
            foreach ([$this->logFile, $this->logFile . '.1'] as $leaf) {
                if (is_file($leaf)) {
                    @unlink($leaf);
                }
            }
            @rmdir($this->tempDir);
        }
        $this->tempDir = '';
        parent::tearDown();
    }

    private function runCommand(string $text, ?string $sessionId = null): string
    {
        $reply = (new HooksHostCommand())->run(
            CommandContext::new(history: [Message::user('go')], root: $this->tempDir . '/project', sessionId: $sessionId),
            $text,
        );

        return implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));
    }

    /**
     * Seed the pinned leaf with $count entries for $session, oldest first —
     * through the real writer, not hand-built JSON: a reader test that
     * fabricated the wire could pass on a format nobody writes.
     */
    private function seed(int $count, string $session = 'log-reader-session', string $tag = 'lr'): void
    {
        $writer = new HookLog();
        for ($i = 1; $i <= $count; $i++) {
            $writer->record(HookLogEntry::forRun(
                HookEvent::PreToolUse->value,
                new HookContext(
                    sessionId: $session,
                    toolName: 'Bash',
                    toolArgs: [],
                    toolInput: '',
                    toolOutput: '',
                    model: 'probe-model',
                    provider: 'probe',
                    projectRoot: '/',
                ),
                'seed-' . $i,
                new HookResult(HookResult::ALLOW, $tag . $i),
                0.003,
            ));
        }
    }

    private function chainHook(string $name, HookEvent $event, string $matcher): HookInterface
    {
        return new class ($name, $event, $matcher) implements HookInterface {
            public function __construct(
                private readonly string $hookName,
                private readonly HookEvent $hookEvent,
                private readonly string $hookMatcher,
            ) {
            }

            public function name(): string
            {
                return $this->hookName;
            }

            public function event(): HookEvent
            {
                return $this->hookEvent;
            }

            public function matcher(): string
            {
                return $this->hookMatcher;
            }

            public function execute(HookContext $context): HookResult
            {
                return new HookResult(HookResult::ALLOW, '');
            }

            public function isReserved(): bool
            {
                return false;
            }
        };
    }

    public function testAnUnwrittenLogAnswersTheHeadingAndTheNoneRow(): void
    {
        $text = $this->runCommand('/hooks log', 'nobody-ran');

        self::assertStringContainsString('**Hook log**', $text);
        self::assertStringContainsString('No hook-log entries for this session yet.', $text);
        self::assertFileDoesNotExist($this->logFile, 'reading must not create the leaf');
    }

    public function testTheDefaultFormReadsTheTailNotTheWholeFile(): void
    {
        $this->seed(HookLog::DEFAULT_ROWS + 5);

        $text = $this->runCommand('/hooks log', 'log-reader-session');

        // DEFAULT_ROWS freshest shown: the OLDER seeds are cut, every one of
        // the newest DEFAULT_ROWS carries. Row identity rides the Message
        // column with a short token — wider columns truncate at the default
        // pane and a truncated needle cannot be asserted.
        self::assertStringNotContainsString('lr1 ', $text);
        self::assertStringContainsString('lr' . (HookLog::DEFAULT_ROWS + 5) . ' ', $text);
        self::assertStringContainsString('lr6 ', $text);
    }

    public function testAnExplicitNumberAsksForThatManyRows(): void
    {
        $this->seed(6);

        $text = $this->runCommand('/hooks log 2', 'log-reader-session');

        self::assertStringNotContainsString('lr4 ', $text, 'older than the two asked for');
        self::assertStringContainsString('lr5 ', $text);
        self::assertStringContainsString('lr6 ', $text);
    }

    public function testASessionFilterHidesOtherSessionsRows(): void
    {
        $this->seed(2, 'session-alpha', 'al');
        $this->seed(2, 'session-beta', 'be');

        $text = $this->runCommand('/hooks log', 'session-alpha');

        self::assertStringContainsString('al1 ', $text);
        self::assertStringNotContainsString('session-beta', $text, 'the tail is this session\'s story, not the machine\'s');
    }

    public function testANonNumericAskRefusesLoudlyInsteadOfClamping(): void
    {
        $this->seed(3);

        foreach (['x', '0', '-3', '2.5', (string) (HookLog::MAX_ROWS + 1)] as $bad) {
            $text = $this->runCommand('/hooks log ' . $bad, 'log-reader-session');
            self::assertStringContainsString('Usage: /hooks log [n]', $text, 'refused form: ' . $bad);
            self::assertStringNotContainsString('lr', $text, 'a refusal must not also render rows for: ' . $bad);
        }
    }

    public function testTheMaximumAskIsHonouredAtTheBoundary(): void
    {
        $this->seed(HookLog::DEFAULT_ROWS + 2);

        $text = $this->runCommand('/hooks log ' . HookLog::MAX_ROWS, 'log-reader-session');

        self::assertStringContainsString('lr1 ', $text, 'the cap asks for everything kept');
    }

    public function testAnUnknownFirstWordIsAnsweredNotIgnored(): void
    {
        $text = $this->runCommand('/hooks logs');

        self::assertStringContainsString('Unknown /hooks argument: logs', $text);
        self::assertStringNotContainsString('**Hook log**', $text);
    }

    public function testTheBareFormStillRidesTheCarriedChain(): void
    {
        $registry = new HookRegistry();
        $registry->register($this->chainHook('bare-panel-' . getmypid(), HookEvent::PreToolUse, 'Bash'));

        $context = CommandContext::new(
            history: [Message::user('go')],
            root: $this->tempDir . '/project',
            workspace: WorkspaceContext::new(hooks: new HookManager($registry)),
        );
        $reply = (new HooksHostCommand())->run($context, '/hooks');
        $text = implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));

        self::assertStringContainsString('bare-panel-', $text);
        self::assertStringNotContainsString('**Hook log**', $text, 'the bare form is the panel, not the log');
    }

    public function testTheLogFormShortCircuitsBeforeTheChainQuestion(): void
    {
        // No workspace chain at all: the BARE form answers "not measured",
        // but `log` reads a file the registry writes — it must answer the
        // tail (or the none row) regardless of what chain the host carries.
        $this->seed(1);

        $reply = (new HooksHostCommand())->run(
            CommandContext::new(history: [Message::user('go')], root: $this->tempDir . '/project', sessionId: 'log-reader-session'),
            '/hooks log',
        );
        $text = implode("\n", array_map(static fn (Message $m): string => $m->content, $reply->rows));

        self::assertStringContainsString('lr1 ', $text);
        self::assertStringNotContainsString('not measured', $text);
    }

    public function testAnUnreadableLeafAnnouncesItselfInsteadOfRenderingEmpty(): void
    {
        $this->seed(2);
        // Turn the readable leaf into a directory: the window walk refuses
        // (an unreadable half is announced, never rendered as "no entries").
        unlink($this->logFile);
        mkdir($this->logFile, 0700);

        try {
            $text = $this->runCommand('/hooks log', 'log-reader-session');

            self::assertStringContainsString('could not be read in part', $text);
            // Honest at zero: the empty row carries its REASON alongside the
            // unreadable announcement rather than standing alone as a lie.
            self::assertStringContainsString('No hook-log entries', $text);
        } finally {
            @rmdir($this->logFile);
        }
    }

    public function testEntriesRollBackThroughTheRotatedGeneration(): void
    {
        // The reader fills from the previous generation when the current one
        // is short — the rotation a writer performs must not truncate what
        // `/hooks log 5` can show below the kept window.
        $this->seed(2, 'rotate-reader-session', 'ro');
        rename($this->logFile, $this->logFile . HookLog::ROTATED_SUFFIX);
        // The post-rotation generation restarts its index; a fresh tag keeps
        // the three rows distinguishable through the reader.
        $this->seed(1, 'rotate-reader-session', 'rc');

        $text = $this->runCommand('/hooks log 3', 'rotate-reader-session');

        self::assertStringContainsString('ro1 ', $text, 'the .1 generation is part of the tail');
        self::assertStringContainsString('ro2 ', $text);
        self::assertStringContainsString('rc1 ', $text);
    }
}

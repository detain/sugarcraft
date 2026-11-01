<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\PtyRun;
use SugarCraft\Code\Tools\BuiltIn\ShellOutput;
use SugarCraft\Code\Tools\BuiltIn\ShellSend;
use SugarCraft\Code\Tools\Catalog\BuiltInTool;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;
use SugarCraft\Code\Tools\Catalog\ToolPermissionClass;

/**
 * C-TOOL-6.k: the typing half of the interactive background shell channel.
 *
 * The engine's doors (send()'s own refusals, the cursor-before pairing) are
 * {@see \SugarCraft\Code\Tests\Sessions\BackgroundShellInteractiveTest}'s
 * subject; what is pinned HERE is the TOOL — its catalog entry, its argument
 * door, the refusal shapes the model actually reads, the wait ceiling, and
 * the live send→read round trip through a real relay.
 *
 * THIS FILE NEVER SKIPS, following the 6.j host discipline: the machine that
 * ships the phar carries ffi/posix/pcntl and a ptmx, so every live leg here
 * EXECUTES; a substrate regression turns these pins red, which is the honest
 * answer. The cursor-before write is the whole pairing race-freedom law —
 * the read threads exactly the cursor send() returned, so bytes a keystroke
 * produced can never be missed and old ones never replayed into the answer.
 */
final class ShellSendTest extends TestCase
{
    private string $c6kRoot;

    /** @var list<BackgroundShellRegistry> */
    private array $c6kRegistries = [];

    protected function setUp(): void
    {
        $this->c6kRoot = (string) sys_get_temp_dir() . '/c6k-send-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->c6kRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        // The launch's teardown guarantee runs FIRST (the 6.j law): no relay
        // or pty shell of this test survives into the file sweep.
        foreach ($this->c6kRegistries as $registry) {
            $registry->teardown();
        }
        $this->c6kRegistries = [];
        foreach (scandir($this->c6kRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->c6kRoot . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->c6kRoot);
    }

    private function c6kRegistry(string $session = 'c6k-test'): BackgroundShellRegistry
    {
        $registry = BackgroundShellRegistry::forSession($session, $this->c6kRoot);
        $this->c6kRegistries[] = $registry;

        return $registry;
    }

    /** The house construction seam: the catalog builds these tools. */
    private function c6kSendTool(?BackgroundShellRegistry $registry = null): ShellSend
    {
        $skills = new SkillRegistry();

        return ShellSend::fromCatalog(new ToolBuildContext(
            root: $this->c6kRoot,
            loader: new InstructionFileLoader($this->c6kRoot),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    /** The read-back seam for the threaded cursor — the same builder as the send tool. */
    private function c6kOutputTool(?BackgroundShellRegistry $registry): ShellOutput
    {
        $skills = new SkillRegistry();

        return ShellOutput::fromCatalog(new ToolBuildContext(
            root: $this->c6kRoot,
            loader: new InstructionFileLoader($this->c6kRoot),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function c6kWaitC6kSettled(BackgroundShellRegistry $registry, string $id): void
    {
        for ($i = 0; $i < 160; $i++) {
            if (($registry->status($id)['state'] ?? '') !== 'running') {
                return;
            }
            usleep(25_000);
        }
        self::fail('shell ' . $id . ' never settled');
    }

    // ── the catalog entry ────────────────────────────────────────────────

    public function testTheCatalogEntryIsWriteClassAtPositionFortySeven(): void
    {
        $attributes = (new \ReflectionClass(ShellSend::class))->getAttributes(BuiltInTool::class);
        self::assertCount(1, $attributes);
        $declaration = $attributes[0]->newInstance();

        self::assertSame('ShellSend', $declaration->name);
        // DESIGN DEVIATION, benign per the C-TOOL-6.d precedent (ScriptTool
        // landed 46 against its design's 44): the design spells position 43,
        // but by this step the disk holds 43-46 — SkillWorkshop (C-EXT-3.e),
        // Advisor, Canvas (C-TOOL-6.f) and Script (C-TOOL-6.d) — and 47 is
        // the next free slot, which keeps ShellSend unconditional and last,
        // exactly the ordering the design's block describes. Positions are
        // ordering metadata; the gloss below is verbatim.
        self::assertSame(47, $declaration->position);
        self::assertSame(ToolPermissionClass::Write, $declaration->permission);
        self::assertSame(
            'type keystrokes into an interactive background shell started with Bash run_in_background and interactive',
            $declaration->gloss,
            'the gloss is the design sentence verbatim',
        );
        self::assertSame('ShellSend', ShellSend::NAME);
    }

    public function testTheSchemaRequiresThePairAndCeilsTheWait(): void
    {
        $schema = ShellSend::new()->inputSchema();

        self::assertSame(['shell_id', 'keys'], $schema['required']);
        self::assertSame(0, $schema['properties']['wait_ms']['minimum']);
        // The ceiling is E646-clean BY CONSTRUCTION: it bounds this call's
        // patience reading the screen, never a wall clock on the shell.
        self::assertSame(10_000, $schema['properties']['wait_ms']['maximum']);
        self::assertSame(10_000, ShellSend::MAX_WAIT_MS);
        self::assertSame(500, ShellSend::DEFAULT_WAIT_MS);
        self::assertStringContainsString('4096', $schema['properties']['keys']['description'], 'the byte cap is disclosed where the caller chooses the size');
    }

    public function testTheCapSentenceCarriesTheEnginesOwnConstant(): void
    {
        // The schema restates 4096 in prose; the truth lives in the private
        // engine constant. If the two ever diverge, the sentence the model
        // reads becomes a lie — so the pair is pinned across the boundary.
        $constant = (new \ReflectionClass(BackgroundShellRegistry::class))->getReflectionConstant('SEND_MAX_BYTES');
        self::assertIsObject($constant);
        self::assertSame(4096, $constant->getValue());
    }

    public function testTheToolIsNotAShellSubjectAndItsArgumentsDoNotReadAsOne(): void
    {
        // The class docblock's roster argument, EXEC'd: ShellSend never joins
        // the shell-subject roster, and its keystrokes never masquerade as
        // `command` text for the matchers that parse shell lines.
        self::assertFalse(\SugarCraft\Code\Permissions\ShellSubjectTools::covers(ShellSend::NAME));
        self::assertSame([], \SugarCraft\Code\Permissions\ShellSubjectTools::stepTexts(ShellSend::NAME, ['command' => 'rm -rf /', 'keys' => 'x<Enter>']));
        self::assertSame([], \SugarCraft\Code\Permissions\ShellSubjectTools::judgedShellTexts(ShellSend::NAME, ['command' => 'rm -rf /']));
        self::assertContains(ShellSend::NAME, \SugarCraft\Code\Runtime::WRITE_CAPABLE_TOOL_NAMES, 'typed input moves the program holding the terminal — the roster ShellStop justified');
    }

    // ── the argument door ────────────────────────────────────────────────

    public function testAMalformedShellIdIsRefusedAtTheDoor(): void
    {
        $result = $this->c6kSendTool($this->c6kRegistry())->execute(['shell_id' => 'bash-1', 'keys' => 'ls<Enter>']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-xxxxxxxx', $result->content());
    }

    public function testAToolWithoutARegistryRefusesLoudly(): void
    {
        $result = ShellSend::new()->execute(['shell_id' => 'sh-deadbeef', 'keys' => 'ls<Enter>']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell registry is attached', $result->content());
    }

    public function testEmptyKeysAreRefusedWithoutTouchingAnyShell(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('sleep 40', 'keys door');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => '']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('non-empty string', $result->content());
        // The door refused BEFORE the registry: nothing was typed, and the
        // input stamp stayed unset — a refusal must not move the clock.
        self::assertNull(array_key_exists('lastInputAt', $registry->status($id)) ? $registry->status($id)['lastInputAt'] : 'minted', 'the refused row kept an unset input stamp');
    }

    public function testAnUnknownIdAnswersExactlyLikeAForeignOne(): void
    {
        $result = $this->c6kSendTool($this->c6kRegistry())->execute(['shell_id' => 'sh-deadbeef', 'keys' => 'ls<Enter>']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell sh-deadbeef is tracked by this launch', $result->content());
    }

    public function testAForeignOwnersShellIsInvisibleToTheSend(): void
    {
        $registry = $this->c6kRegistry('c6k-owner-a');
        $started = $registry->startInteractive('sleep 40', 'foreign probe', 80, 24, [], 'sess-A');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $result = $this->c6kSendTool($registry)->withShellOwner('sess-B')
            ->execute(['shell_id' => $id, 'keys' => 'x<Enter>']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('is tracked by this launch', $result->content(), 'a foreign id takes the unknown-id answer — no probe of which case fired');

        $untouched = $registry->status($id, 'sess-A');
        self::assertSame(0, (int) ($untouched['humanInputs'] ?? -1));
        self::assertNull(array_key_exists('lastInputAt', $untouched) ? $untouched['lastInputAt'] : 'minted', 'the stranger call stamped nothing on the real owner row');
    }

    public function testAPlainBackgroundShellRefusesTypedInputWithTheFixNamed(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->start('sleep 40', 'plain row');
        $id = (string) $started['shell']['id'];

        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => 'x<Enter>']);
        self::assertTrue($result->isError());
        $text = $result->content();
        self::assertStringContainsString('is not an interactive (pty) shell', $text);
        self::assertStringContainsString('start it with interactive: true', $text, 'the design sentence names the fix in the refusal');
        self::assertStringContainsString('a plain background shell has no terminal to type into', $text);
    }

    public function testASettledInteractiveShellRefusesTypingAgain(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('true', 'settled probe');
        $id = (string) $started['shell']['id'];
        $this->c6kWaitC6kSettled($registry, $id);

        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => 'x<Enter>', 'wait_ms' => 0]);
        self::assertTrue($result->isError());
        self::assertStringContainsString('has already finished; start a new one to type again', $result->content());
    }

    public function testAWholeCappedSendIsRefusedBeforeAnyByteIsTyped(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('cat', 'cap probe');
        $id = (string) $started['shell']['id'];
        $cursorBefore = (int) (@filesize($registry->status($id)['stdoutPath']) ?: 0);

        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => str_repeat('a', 4097)]);
        self::assertTrue($result->isError());
        self::assertStringContainsString('a send is capped at 4096 bytes; this one carried 4097', $result->content());
        self::assertSame($cursorBefore, (int) (@filesize($registry->status($id)['stdoutPath']) ?: 0), 'the oversize template typed nothing — the refusal is in front of the FIFO, not after a partial write');
    }

    // ── the grammar ──────────────────────────────────────────────────────

    public function testTheGrammarIsTheSharedPtyRunOne(): void
    {
        // The design's single-grammar law: ShellSend does not re-spell the
        // decoder, it routes through PtyRun::decode — the two anchors below
        // are what the fold sends, byte for byte.
        self::assertSame("ls\r", PtyRun::decode('ls<Enter>'));
        self::assertSame("\x03", PtyRun::decode('<C-c>'));
    }

    public function testTheWireCarriesTheDecodedBytesAndLiteralUnknownTokens(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('cat', 'grammar probe');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $marker = 'c6k-' . bin2hex(random_bytes(4));
        // The template mixes a known token (<Enter> submits) with an unknown
        // angle shape that must survive as itself — the grammar adds, never
        // strips, so there is nothing else to refuse.
        $result = $this->c6kSendTool($registry)->execute([
            'shell_id' => $id,
            'keys' => $marker . '<x>' . '<Enter>',
            'wait_ms' => 800,
        ]);
        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString($marker . '<x>', $result->content(), 'the echoed line comes back with the unknown token literal and the Enter consumed');
        self::assertStringContainsString('sent ' . (strlen($marker) + 4) . ' bytes', $result->content(), 'the head counts DECODED bytes (<Enter> folds to one byte the template did not have), not template bytes');
        self::assertStringNotContainsString("\x1b", $result->content(), 'the screen answer is ANSI-stripped like every other interactive read');
    }

    // ── the live round trip (cursor-before pairing) ─────────────────────

    public function testALiveSendAnswersTheScreenThatFollowedAndThreadsTheCursor(): void
    {
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('cat', 'live round trip');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $marker = 'c6kecho' . bin2hex(random_bytes(4));
        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => $marker . '<Enter>', 'wait_ms' => 800]);
        self::assertFalse($result->isError(), $result->content());
        $text = $result->content();
        self::assertStringContainsString($marker, $text, 'what was typed is what the terminal gave back');
        self::assertMatchesRegularExpression('/\[cursor: \d+\.\d+\]/', $text, 'the answer threads the two-part token the next read takes back');

        $cursor = (string) (preg_match('/\[cursor: (\d+\.\d+)\]/', $text, $m) ? $m[1] : '');
        $drained = $this->c6kOutputTool($registry)->execute(['shell_id' => $id, 'cursor' => $cursor]);
        self::assertFalse($drained->isError());
        self::assertStringNotContainsString($marker, $drained->content(), 'a threaded cursor never replays the bytes the send answer already carried');

        // A model send is not a human touch: the audit counter stays at zero.
        self::assertSame(0, (int) $registry->status($id)['humanInputs']);
        self::assertIsFloat($registry->status($id)['lastInputAt'] ?? null, 'the stamp rides every accepted send, model or human');
    }

    public function testAScreenThatDoesNotAnswerYetIsNarratedAsSuch(): void
    {
        $registry = $this->c6kRegistry();
        // stty kills the tty's own echo: with a sleeping foreground program
        // AND no line-discipline echo, nothing in the terminal can answer
        // the keystroke — the only honest way to make the screen stand still
        // inside the wait (the ECHOING case is pinned live in the grammar
        // probe above).
        $started = $registry->startInteractive('stty -echo; printf READY; sleep 40', 'no answer yet');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        // The READY marker is stty's own sequencing proof: the script runs
        // top to bottom, so once it is on the screen the echo is verifiably
        // off. Typing after that point cannot be answered by the terminal.
        $seenDeadline = microtime(true) + 12.0;
        while (!str_contains((string) $registry->read($id)['stdout'], 'READY') && microtime(true) < $seenDeadline) {
            usleep(25_000);
        }
        self::assertStringContainsString('READY', (string) $registry->read($id)['stdout'], 'the echo-killer never printed');

        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => 'x', 'wait_ms' => 250]);
        self::assertFalse($result->isError());
        // The cursor the send returned sits at READY's end; the threaded
        // read starts there, so the marker itself is not part of the answer.
        self::assertStringNotContainsString('READY', $result->content());
        self::assertStringContainsString('no output in the wait', $result->content());
        self::assertStringContainsString('read more with ShellOutput', $result->content());
        self::assertMatchesRegularExpression('/\[cursor: \d+\.\d+\]/', $result->content());
    }

    public function testADeletedFifoCostsTheAttachWindowAndRefusesHonestly(): void
    {
        // The 6.j hand-off NIT (the sub-ms window between the relay's .pid
        // landing and its read-side open) is EXEC-proven from the other
        // side here: with the FIFO unlinked, the retry loop keeps hitting
        // the attach-window shape, the row stays honestly running (a dead
        // fifo is not a finished shell), and after the declared 2-second
        // window the tool answers the engine's own refusal — never a hang.
        $registry = $this->c6kRegistry();
        $started = $registry->startInteractive('sleep 40', 'dowse fifo');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/ShellSend.php');
        self::assertSame(1, substr_count($source, 'the input FIFO could not be opened; the relay may have just died'), 'the attach-window predicate names the fifo-open shape exactly once');
        self::assertSame(1, substr_count($source, 'the relay is not reading input; the send was refused'), 'and the not-draining shape exactly once');
        self::assertStringContainsString('RELAY_ATTACH_BUDGET_MS = 2_000', $source, 'the window is the declared per-operation ceiling');

        $readyPidPath = (string) ($registry->status($id)['ptyPidPath'] ?? '');
        $readyDeadline = microtime(true) + 12.0;
        while (!is_file($readyPidPath) && microtime(true) < $readyDeadline) {
            usleep(20_000);
        }
        self::assertIsReadable($readyPidPath, 'the relay never recorded its shell pid — the dowser leg has no attached pump to detach');
        self::assertTrue(@unlink((string) $registry->status($id)['inPath']), 'the test dowser removed the fifo AFTER the pump attached (unlinking earlier would kill the relay itself, not simulate the window)');
        $startedAt = microtime(true);
        $result = $this->c6kSendTool($registry)->execute(['shell_id' => $id, 'keys' => 'x<Enter>', 'wait_ms' => 0]);
        $elapsed = microtime(true) - $startedAt;

        self::assertTrue($result->isError());
        self::assertStringContainsString('could not be opened', $result->content());
        self::assertGreaterThan(1.5, $elapsed, 'the retry loop actually rode the window before answering — the predicate fired, not just the text');
        self::assertLessThan(5.0, $elapsed, 'the window is bounded; a dead relay must not stall the turn');
        self::assertSame('running', (string) $registry->status($id)['state'], 'a closed input pipe never reads as a finished shell');
    }

    // ── session-state carriage (the deviation pin) ───────────────────────

    public function testRowsRideTheSessionStateBoundary(): void
    {
        $registry = $this->c6kRegistry('c6k-state-a');
        $started = $registry->startInteractive('sleep 40', 'state probe');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $state = $this->c6kSendTool($registry)->exportSessionState();
        // The carrier is the same launch directory under a fresh instance —
        // rows are path-scoped (a stranger root must NOT take them, the same
        // law that makes the dropForkInheritedHandles re-derivation safe).
        $carrier = $this->c6kRegistry('c6k-state-a');
        $carrier->merge($state[BackgroundShellRegistry::SESSION_STATE_KEY] ?? null);

        $row = $carrier->status($id);
        self::assertIsArray($row, 'the send tool exports under the registry key and a merged launch sees the row');
        self::assertTrue((bool) ($row['interactive'] ?? false), 'the interactive half of the row rode the boundary — a merged shell must still accept typing');
    }
}

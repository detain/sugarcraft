<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\ShellOutput;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;

/**
 * SHL-1: the read half of the background shell channel. The registry owns
 * cursor arithmetic (pinned in tests/Sessions); what is pinned HERE is the
 * tool's door (id validation, missing registry) and the PAINT — the exact
 * sentences the model reads, including the honest state wording and the
 * cursor it is told to pass back.
 */
final class ShellOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-out-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->root . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    private function registry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('w2d-test', $this->root);
    }

    /** The house construction seam: the catalog builds these tools. */
    private function tool(?BackgroundShellRegistry $registry = null): ShellOutput
    {
        $skills = new SkillRegistry();

        return ShellOutput::fromCatalog(new ToolBuildContext(
            root: $this->root,
            loader: new InstructionFileLoader($this->root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    private function settle(BackgroundShellRegistry $registry, string $id): void
    {
        for ($i = 0; $i < 160; $i++) {
            if (($registry->status($id)['state'] ?? '') === 'finished') {
                return;
            }
            usleep(25_000);
        }
        self::fail('shell ' . $id . ' never settled');
    }

    /**
     * C-TOOL-6.k: the interactive half of the paint. A pty screen is not a
     * log — its bytes are colors and cursor movement — so an interactive
     * row's display text comes back ANSI-stripped, the terminal size rides
     * beside the state, and human typing is reported: total on the first
     * read a window makes, then the count SINCE that read. Plain rows keep
     * the byte-faithful framing above, untouched.
     */
    public function testAnInteractiveRowIsStrippedSizedAndReportsTheHumanHand(): void
    {
        $registry = $this->registry();
        $started = $registry->startInteractive("printf '\\033[31mREDCOL\\033[0m\\n'; sleep 60", 'strip probe');
        self::assertTrue((bool) ($started['ok'] ?? false));
        $id = (string) $started['shell']['id'];

        self::assertTrue(\SugarCraft\Code\Tools\BuiltIn\ShellSend::relayReady($registry->status($id)), 'the relay attached within its declared budget');
        for ($i = 0; $i < 80; $i++) {
            if (str_contains((string) $registry->read($id, 0)['stdout'], 'REDCOL')) {
                break;
            }
            usleep(25_000);
        }

        $tool = $this->tool($registry);
        $first = $tool->execute(['shell_id' => $id]);
        self::assertFalse($first->isError(), $first->content());
        $text = $first->content();
        self::assertStringContainsString('interactive pty 80x24', $text, 'the row answers as a terminal, with its size');
        self::assertStringContainsString('ANSI-stripped', $text);
        self::assertStringContainsString('REDCOL', $text, 'the words on the screen survive the strip');
        self::assertStringNotContainsString("\x1b", $text, 'no SGR byte reaches the model from an interactive row');
        self::assertStringNotContainsString('typed into this shell', $text, 'nobody has touched it; nothing is claimed');

        $registry->send($id, 'x', 'human');
        $second = $tool->execute(['shell_id' => $id]);
        self::assertStringContainsString('1 times since your last read', $second->content(), 'the same tool instance anchored its first read at zero touches — the next report is the delta since that read');

        $registry->send($id, 'y', 'human');
        $third = $this->tool($registry)->execute(['shell_id' => $id]);
        self::assertStringContainsString('2 times in all', $third->content(), 'a DIFFERENT window has no anchor to speak of — it reports the total, never a delta it cannot name');

        $registry->stop($id);
    }

    public function testAMalformedShellIdIsRefusedWhenReading(): void
    {
        $result = $this->tool()->execute(['shell_id' => 'bash-1']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-xxxxxxxx', $result->content());
    }

    public function testAToolWithoutARegistryRefusesLoudly(): void
    {
        $result = ShellOutput::new()->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell registry is attached', $result->content());
    }

    public function testAnUnregisteredIdIsRefusedByName(): void
    {
        $tool = $this->tool($this->registry());
        $result = $tool->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-deadbeef', $result->content());
        self::assertStringContainsString('is registered by this launch', $result->content());
    }

    public function testThePaintCarriesTheStateLineTheStreamsAndTheNextCursor(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2; exit 3', 'paint');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'max_bytes' => 4]);
        self::assertFalse($result->isError());
        $text = $result->content();
        self::assertStringStartsWith('shell ' . $id . ' — finished (exit 3)', $text);
        // An explicit window is honoured byte-for-byte: stdout fills it, the
        // token lands at 4.0, and nothing is silently destroyed — the next
        // step from the token collects stderr (the walk never skips inside
        // a window).
        self::assertStringContainsString('AAAA', $text);
        self::assertStringContainsString('[cursor: 4.0]', $text);

        $next = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => '4.0', 'max_bytes' => 4]);
        self::assertFalse($next->isError());
        self::assertStringContainsString('[stderr]', $next->content());
        self::assertStringContainsString('BBBB', $next->content());
        self::assertStringContainsString('[cursor: 4.4]', $next->content());
    }

    public function testTheSchemaAdvertisesTheTwoPartCursorToken(): void
    {
        $cursor = $this->tool()->inputSchema()['properties']['cursor'];
        self::assertSame('string', $cursor['type'], 'the cursor is the opaque "<stdout>.<err>" token (C-TOOL-1)');
        self::assertStringContainsString('.<stderr>', $cursor['description'], 'the description teaches the token shape the answer paints');
    }

    public function testALegacyIntegerCursorStillThreadsAFrozenStdout(): void
    {
        // A cursor threaded before the upgrade must keep working on the
        // frozen-stdout shape the legacy arithmetic was exact for.
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2', 'legacy int');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => 4, 'max_bytes' => 4]);
        self::assertFalse($result->isError());
        self::assertStringContainsString('BBBB', $result->content(), 'the legacy combined offset decodes to stdout 4 / stderr 0');
        self::assertStringContainsString('[cursor: 4.4]', $result->content(), 'the answer continues in the token shape');
    }

    public function testAnUnparsableCursorReadsFromTheStart(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2', 'junk cursor');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => 'not-a-cursor']);
        self::assertFalse($result->isError());
        self::assertStringContainsString('AAAA', $result->content(), 'garbage never silently picks a position — it restarts');
        self::assertStringContainsString('BBBB', $result->content());
        self::assertStringContainsString('[cursor: 4.4]', $result->content());
    }

    public function testAQuietWindowPaintsTheEmptyNoticeInsteadOfNothing(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf hello', 'quiet');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => 5]);
        self::assertFalse($result->isError());
        self::assertStringContainsString('(no new output)', $result->content());
    }

    public function testATailSkipIsAdmittedInProse(): void
    {
        $registry = $this->registry();
        $blob = str_repeat('OLDOLDNEWNEW', 6000); // 72,000 bytes, past the default budget
        $started = $registry->start('printf ' . escapeshellarg($blob), 'big');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        // The state-line head is searched, not the whole 64 KB answer: this
        // box's PHP 8.3.6 zend_mem_memmem fast path mis-answers on haystacks
        // this large (needle bytes proven correct by hex dump; the same needle
        // against a short haystack matches). Head-first is also where the note
        // structurally lives — paint puts it on the state line.
        $head = substr($result->content(), 0, 200);
        self::assertStringContainsString('older bytes skipped', $head);
        self::assertStringContainsString('(72000 unread bytes were available)', $head);
        self::assertSame('[cursor: 72000.0]', substr(trim($result->content()), -17));
    }

    public function testTheRunStateIsNarratedHonestlyMidFlight(): void
    {
        $registry = $this->registry();
        $started = $registry->start('sleep 30', 'live');
        $id = (string) $started['shell']['id'];

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        self::assertStringStartsWith('shell ' . $id . ' — running', $result->content());

        $registry->teardown();
        self::assertNotSame('running', $registry->status($id)['state']);
    }

    public function testTheExportCarriesTheRegistryRowsUnderTheSessionKey(): void
    {
        $registry = $this->registry();
        $tool = $this->tool($registry);
        $started = $registry->start('true', 'export');
        $id = (string) $started['shell']['id'];

        $export = $tool->exportSessionState();
        self::assertArrayHasKey(BackgroundShellRegistry::SESSION_STATE_KEY, $export);
        self::assertArrayHasKey($id, $export[BackgroundShellRegistry::SESSION_STATE_KEY]);

        // Round trip through a sibling instance wired to the same registry
        // (the fork-boundary contract): an empty-table fresh registry
        // recovers the row verbatim.
        $fresh = $this->tool(BackgroundShellRegistry::forSession('w2d-test', $this->root));
        $fresh->mergeSessionState($export);
        $again = $fresh->exportSessionState();
        self::assertSame([$id], array_keys($again[BackgroundShellRegistry::SESSION_STATE_KEY]));

        // Malformed payloads are ignored, never fatal.
        $fresh->mergeSessionState(['backgroundShells' => 'scalar']);
        $fresh->mergeSessionState([]);
        self::assertCount(1, $fresh->exportSessionState()[BackgroundShellRegistry::SESSION_STATE_KEY]);
    }

    public function testThePaintedFinishNamesTheOutputCapWhenTheExitCodeSaysSo(): void
    {
        // SHL-1: the state word is where the model learns WHY a shell died
        // at 153 — the note rides the read answer and the paint appends it.
        $registry = $this->registry();
        $tool = $this->tool($registry);
        $started = $registry->start('exit 153', 'cap narration');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $tool->execute(['shell_id' => $id, 'cursor' => 0]);
        self::assertFalse($result->isError());
        self::assertStringContainsString('finished (exit 153) — output limit exceeded', $result->content());

        $plain = $registry->start('exit 3', 'plain failure');
        $plainId = (string) $plain['shell']['id'];
        $this->settle($registry, $plainId);
        $plainResult = $tool->execute(['shell_id' => $plainId, 'cursor' => 0]);
        self::assertStringContainsString('finished (exit 3)', $plainResult->content());
        self::assertStringNotContainsString('output limit exceeded', $plainResult->content());
    }
}

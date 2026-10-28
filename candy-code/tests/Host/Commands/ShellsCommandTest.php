<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\ShellsCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;

/**
 * C-TOOL-3.d command surface: `/shells` lists the session's own background
 * shells (plus untagged rows), `output <id> [cursor]` reads a window through
 * the registry, and `stop <id>` walks the existing kill ladder — with the
 * read-only judgement (list/output yes, stop no) and the C-TOOL-3.c ownership
 * law pinned at the command edge: a foreign id answers exactly as an unknown
 * one, and the command NEVER spawns (no start verb exists to spawn through).
 *
 * THE WHOLE FILE AVOIDS `$HOME`: registries are built with an explicit temp
 * `$root` (the same door as the registry and ownership suites), real shells
 * are started only to be read or stopped, and liveness is read by polling
 * `status()` — never a one-shot `proc_get_status()` after a pipe drain.
 */
final class ShellsCommandTest extends TestCase
{
    private string $root;

    /** @var list<array{registry: BackgroundShellRegistry, id: string}> live children to stop in tearDown */
    private array $live = [];

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/cc_cmp3d_shells-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        // The kill ladder is the registry's own door; a leaked `sleep 120`
        // outlives the temp tree otherwise.
        foreach ($this->live as $child) {
            $child['registry']->stop($child['id']);
        }
        $this->live = [];
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

    // =====================================================================
    // configuration + read-only judgement
    // =====================================================================

    public function testWithoutAWorkspaceRegistryTheCommandSaysSo(): void
    {
        $result = (new ShellsCommand())->run($this->shellsContext(null), '/shells');
        self::assertStringContainsString('not configured', self::body($result));

        $stop = (new ShellsCommand())->run($this->shellsContext(null), '/shells stop sh-00000000');
        self::assertStringContainsString('not configured', self::body($stop));
    }

    public function testReadOnlyJudgementIsVerbShaped(): void
    {
        self::assertTrue(ShellsCommand::isReadOnlyVerb('list'));
        self::assertTrue(ShellsCommand::isReadOnlyVerb('output'), 'reading a log file changes nothing');
        self::assertFalse(ShellsCommand::isReadOnlyVerb('stop'), 'stop runs the kill ladder on a live child');
        self::assertFalse(ShellsCommand::isReadOnlyVerb('frobnicate'));
        // The judgement is case-insensitive the way the dispatch verb read is.
        self::assertTrue(ShellsCommand::isReadOnlyVerb('LIST'));
    }

    // =====================================================================
    // list
    // =====================================================================

    public function testListWithNothingVisibleSaysSoAndCoversBothEmptyShapes(): void
    {
        $registry = $this->shellsRegistry();
        // Empty table.
        self::assertStringContainsString('No background shells', self::body($this->dispatchShells($registry, '/shells')));
        // Non-empty table, nothing visible to THIS owner (all rows are foreign).
        $start = $registry->start('sleep 120', 'belongs to another session', [], 'session-B');
        self::assertTrue($start['ok']);
        $this->trackShell($registry, (string) $start['shell']['id']);
        self::assertStringContainsString('No background shells', self::body($this->dispatchShells($registry, '/shells list')));
    }

    public function testListShowsOwnAndUntaggedRowsButNeverForeignOnes(): void
    {
        $registry = $this->shellsRegistry();
        $mine = $registry->start('sleep 120', 'mine stays hidden', [], 'session-A');
        $tagless = $registry->start('sleep 120', 'untagged is shared');
        $theirs = $registry->start('sleep 120', 'foreign stays hidden', [], 'session-B');
        foreach ([$mine, $tagless, $theirs] as $started) {
            self::assertTrue($started['ok']);
            $this->trackShell($registry, (string) $started['shell']['id']);
        }

        $listing = self::body($this->dispatchShells($registry, '/shells'));
        self::assertStringContainsString('untagged is shared', $listing);
        self::assertStringContainsString('[running]', $listing, 'a live row reads as running');
        self::assertStringNotContainsString('foreign stays hidden', $listing, 'C-TOOL-3.c visibility law at the command edge');
        self::assertStringContainsString((string) $mine['shell']['id'], $listing);
        self::assertStringContainsString((string) $tagless['shell']['id'], $listing);
        self::assertStringNotContainsString((string) $theirs['shell']['id'], $listing);
    }

    // =====================================================================
    // output
    // =====================================================================

    public function testOutputReadsASettledShellsBytesAndPrintsTheNextCursor(): void
    {
        $registry = $this->shellsRegistry();
        $started = $registry->start('printf "alpha line\nbeta line\n"', 'two lines', [], 'session-A');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];
        $this->trackShell($registry, $id);
        self::assertSame('finished', $this->waitForSettled($registry, $id, 'session-A')['state']);

        $first = self::body($this->dispatchShells($registry, '/shells output ' . $id));
        self::assertStringContainsString('alpha line', $first);
        self::assertStringContainsString('beta line', $first);
        self::assertStringContainsString('exit 0', $first, 'the settled head reads its exit code');
        self::assertMatchesRegularExpression('/Next cursor: \d+\.\d+/', $first, 'the cursor is the registry 2-part form');

        // Re-reading AT the printed cursor owes nothing new — the walk is
        // byte-honest, it does not re-show what was already served.
        preg_match('/Next cursor: (\d+\.\d+)/', $first, $m);
        self::assertSame(1, preg_match('/^(0|\d+\.\d+)$/', $m[1]), 'the cursor shape re-parses as input');
        $again = self::body($this->dispatchShells($registry, '/shells output ' . $id . ' ' . $m[1]));
        self::assertStringContainsString('no output', strtolower($again));

        // A stopped/unknown id answers with the registry's own missing-id
        // string — foreign and unknown are the SAME answer (C-TOOL-3.c).
        $foreign = $registry->start('sleep 120', 'not yours', [], 'session-B');
        $foreignId = (string) $foreign['shell']['id'];
        $this->trackShell($registry, $foreignId);
        $asForeign = self::body($this->dispatchShells($registry, '/shells output ' . $foreignId));
        $asUnknown = self::body($this->dispatchShells($registry, '/shells output sh-deadbeef'));
        self::assertStringContainsString('no background shell ' . $foreignId, $asForeign);
        self::assertSame(
            str_replace($foreignId, 'sh-deadbeef', $asForeign),
            $asUnknown,
            'the two answers differ only by the id they name',
        );
    }

    public function testOutputRejectsACursorThatIsNeitherForm(): void
    {
        $registry = $this->shellsRegistry();
        $started = $registry->start('sleep 120', 'live', [], 'session-A');
        $id = (string) $started['shell']['id'];
        $this->trackShell($registry, $id);

        $result = $this->dispatchShells($registry, '/shells output ' . $id . ' not-a-cursor');
        self::assertStringContainsString('not a number', self::body($result));
        self::assertStringContainsString('Usage', self::body($this->dispatchShells($registry, '/shells output')), 'the verb with no id is usage');
    }

    // =====================================================================
    // stop
    // =====================================================================

    public function testStopRunsTheLadderAndReportsTheSettledExit(): void
    {
        $registry = $this->shellsRegistry();
        $started = $registry->start('sleep 120', 'stop me', [], 'session-A');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];
        $this->trackShell($registry, $id);

        $body = self::body($this->dispatchShells($registry, '/shells stop ' . $id));
        self::assertStringContainsString('stopped', $body);
        $row = $registry->status($id, 'session-A');
        self::assertNotNull($row);
        self::assertSame('finished', $row['state'], 'the ladder settled the child inside the command');

        // Stopping the settled row again is a no-op success, not a refusal.
        $second = self::body($this->dispatchShells($registry, '/shells stop ' . $id));
        self::assertStringContainsString('already finished', $second);
    }

    public function testStopOfForeignAndUnknownAnswerAlikeAndNeverTouchTheChild(): void
    {
        $registry = $this->shellsRegistry();
        $started = $registry->start('sleep 120', 'not yours to stop', [], 'session-B');
        $id = (string) $started['shell']['id'];
        $this->trackShell($registry, $id);

        $asForeign = self::body($this->dispatchShells($registry, '/shells stop ' . $id));
        $asUnknown = self::body($this->dispatchShells($registry, '/shells stop sh-deadbeef'));
        self::assertStringContainsString('No background shell ' . $id, $asForeign);
        self::assertStringContainsString('No background shell sh-deadbeef', $asUnknown);
        $row = $registry->status($id, 'session-B');
        self::assertNotNull($row);
        self::assertSame('running', $row['state'], 'the refused stop left the child alone');
    }

    // =====================================================================
    // grammar
    // =====================================================================

    public function testUnknownVerbIsUsageAndTheCommandNamesNoSpawnDoor(): void
    {
        $registry = $this->shellsRegistry();
        self::assertStringContainsString('Usage', self::body($this->dispatchShells($registry, '/shells frobnicate sh-12345678')));

        // There is deliberately no start/add/run verb: spawning stays the
        // Shell tool's single door (the plan's no-new-spawn-sites law).
        foreach (['start', 'run', 'add', 'spawn'] as $verb) {
            self::assertStringContainsString('Usage', self::body($this->dispatchShells($registry, '/shells ' . $verb . ' echo hi')), "no {$verb} verb exists");
        }
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function shellsRegistry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('cc-cmp3d-launch', $this->root);
    }

    /** The command context: workspace carries the registry, session A is the caller. */
    private function shellsContext(?BackgroundShellRegistry $registry): CommandContext
    {
        $workspace = WorkspaceContext::new(root: $this->root);
        if ($registry !== null) {
            $workspace = $workspace->withService(BackgroundShellRegistry::class, $registry);
        }

        return CommandContext::new(root: $this->root, sessionId: 'session-A', workspace: $workspace);
    }

    private function dispatchShells(BackgroundShellRegistry $registry, string $text): CommandResult
    {
        return (new ShellsCommand())->run($this->shellsContext($registry), $text);
    }

    private function trackShell(BackgroundShellRegistry $registry, string $id): void
    {
        $this->live[] = ['registry' => $registry, 'id' => $id];
    }

    /** Poll the registry's own answer until the shell settles; bounded, never one-shot. */
    private function waitForSettled(BackgroundShellRegistry $registry, string $id, ?string $owner): array
    {
        for ($i = 0; $i < 200; $i++) {
            $row = $registry->status($id, $owner);
            if ($row !== null && $row['state'] !== 'running') {
                return $row;
            }
            usleep(50_000);
        }
        self::fail('shell ' . $id . ' never settled within 10s');
    }

    private static function body(CommandResult $result): string
    {
        $last = $result->rows[\count($result->rows) - 1];

        return $last instanceof Message ? $last->content : (string) $last;
    }
}

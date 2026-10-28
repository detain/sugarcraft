<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\AcceptsShellOwner;
use SugarCraft\Code\Tools\BuiltIn\Bash;
use SugarCraft\Code\Tools\BuiltIn\ShellOutput;
use SugarCraft\Code\Tools\BuiltIn\ShellStop;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;

/**
 * C-TOOL-3.c: session-owned background shells.
 *
 * A served launch shares one BackgroundShellRegistry (and one tool set)
 * across its sessions, so the registry tags every row with the session
 * that started it and scopes its answers by that tag. The law under test
 * is exactly three clauses: a foreign owner gets the SAME "not registered"
 * answer an unknown id gets, an untagged row stays visible to every caller
 * (the pre-tag behavior, and the only honest answer for rows minted before
 * a session row exists), and the tag rides the export-merge channel so a
 * forked child's table round-trip neither loses nor invents owners.
 *
 * THE WHOLE FILE AVOIDS `$HOME`: registries are built with an explicit
 * temp `$root` (same door as the registry suite), and liveness is read by
 * polling `status()` — never a one-shot `proc_get_status()` after a drain.
 */
final class ShellOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-own-' . getmypid() . '-' . bin2hex(random_bytes(4));
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

    private function scopedRegistry(string $session = 'w2d-own'): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession($session, $this->root);
    }

    /** The house construction seam: the catalog builds these tools. */
    private function stopToolFor(BackgroundShellRegistry $registry): ShellStop
    {
        $skills = new SkillRegistry();

        return ShellStop::fromCatalog(new ToolBuildContext(
            root: $this->root,
            loader: new InstructionFileLoader($this->root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    private function outputToolFor(BackgroundShellRegistry $registry): ShellOutput
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

    /** The owner tag as the object actually carries it (promoted private prop). */
    private function claimOf(object $tool): ?string
    {
        return (new \ReflectionProperty($tool, 'shellOwner'))->getValue($tool);
    }

    public function testAForeignOwnerCannotSeeReadOrStopAnotherSessionsShell(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $started = $registry->start('sleep 120', 'A owns this', [], 'session-A');
            self::assertTrue($started['ok']);
            $id = (string) $started['shell']['id'];

            // The ownerless read stays whole: teardown and the launch-side
            // bookkeeping address rows by id, so an untagged caller — which
            // is what every internal path is — must still see the row.
            self::assertNotNull($registry->status($id));
            self::assertNull($registry->status($id, 'session-B'), 'a foreign owner is answered like an unknown id');

            $foreignRead = $registry->read($id, 0, null, 'session-B');
            self::assertNotTrue($foreignRead['ok']);
            $unknownRead = $registry->read('sh-ffffffff', 0, null, 'session-B');
            self::assertNotTrue($unknownRead['ok']);
            self::assertSame(
                str_replace($id, 'sh-X', (string) $foreignRead['error']),
                str_replace('sh-ffffffff', 'sh-X', (string) $unknownRead['error']),
                'a foreign id is answered with the very door an unknown id is',
            );

            $foreignStop = $registry->stop($id, 'session-B');
            self::assertFalse($foreignStop['found']);
            self::assertSame($registry->stop('sh-ffffffff', 'session-B'), $foreignStop);
            self::assertSame('running', $registry->status($id)['state'], 'the foreign attempt touched nothing');
        } finally {
            $registry->teardown();
        }
    }

    public function testTheOwnerStillReadsWhatItStartedAndStopsIt(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $id = (string) $registry->start('printf for-a', 'mine', [], 'session-A')['shell']['id'];
            for ($i = 0; $i < 160; $i++) {
                if (($registry->status($id, 'session-A')['state'] ?? '') === 'finished') {
                    break;
                }
                usleep(25_000);
            }

            self::assertNotNull($registry->status($id, 'session-A'));
            $ownRead = $registry->read($id, 0, null, 'session-A');
            self::assertTrue($ownRead['ok']);
            self::assertStringContainsString('for-a', (string) $ownRead['stdout']);

            $live = (string) $registry->start('sleep 120', 'stop mine', [], 'session-A')['shell']['id'];
            self::assertTrue($registry->stop($live, 'session-A')['found']);
        } finally {
            $registry->teardown();
        }
    }

    public function testAnUntaggedShellStaysVisibleToEveryCaller(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $id = (string) $registry->start('true', 'untagged')['shell']['id'];

            // null is "nobody's yet", not "everybody's enemy": every owner —
            // including another session — reads the row, exactly as before
            // the tag existed.
            self::assertNotNull($registry->status($id, 'anyone'));
            self::assertTrue($registry->read($id, 0, null, 'whoever')['ok']);
            self::assertTrue($registry->stop($id, 'a-stranger')['found']);
            self::assertContains($id, $registry->ownedBy('whatever'));
            self::assertContains($id, $registry->ownedBy(null));
        } finally {
            $registry->teardown();
        }
    }

    public function testOwnedListAnswersTheOwnersRowsAndTheUntaggedOnes(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $idA = (string) $registry->start('sleep 120', 'A', [], 'session-A')['shell']['id'];
            $idB = (string) $registry->start('sleep 120', 'B', [], 'session-B')['shell']['id'];
            $idN = (string) $registry->start('sleep 120', 'untagged')['shell']['id'];

            self::assertSame([$idA, $idN], $registry->ownedBy('session-A'));
            self::assertSame([$idB, $idN], $registry->ownedBy('session-B'));
            self::assertSame([$idA, $idB, $idN], $registry->ownedBy(null), 'the launch itself sees every row');
            self::assertSame($registry->ids(), $registry->ownedBy(null), 'the null owner is the whole table in start order');
            self::assertSame([$idN], $registry->ownedBy('session-C'), 'a session with none of its own sees only the untagged rows');
        } finally {
            $registry->teardown();
        }
    }

    public function testTheOwnerSurvivesTheExportMergeRoundTripAndUntaggedRowsStayUntagged(): void
    {
        $spawner = $this->scopedRegistry();
        try {
            $id = (string) $spawner->start('printf rides', 'rides the wire', [], 'session-A')['shell']['id'];
            $export = $spawner->toArray();
            self::assertArrayHasKey('owner', $export[$id]);
            self::assertSame('session-A', $export[$id]['owner']);

            // A sibling instance (the shape a forked child or a second
            // handle takes) sees the row only through the merged table, and
            // the merge keeps the claim.
            $merged = $this->scopedRegistry();
            self::assertSame([], $merged->ids());
            $merged->merge($export);
            self::assertNotNull($merged->status($id, 'session-A'));
            self::assertNull($merged->status($id, 'session-B'));

            // A pre-tag export (a child built before this step shipped)
            // merges to an untagged row — visible to all, never silently
            // claimed by whoever merged it.
            $legacy = $export;
            unset($legacy[$id]['owner']);
            $latecomer = $this->scopedRegistry();
            $latecomer->merge($legacy);
            self::assertNotNull($latecomer->status($id, 'session-B'));
            self::assertContains($id, $latecomer->ownedBy('anyone'));
        } finally {
            $spawner->teardown();
        }
    }

    public function testShellOutputBoundToAnotherSessionRefusesTheForeignShellLoudly(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $id = (string) $registry->start('printf hi', 'A owns it', [], 'session-A')['shell']['id'];

            $tool = $this->outputToolFor($registry);
            $refused = $tool->withShellOwner('session-B')->execute(['shell_id' => $id]);
            self::assertTrue($refused->isError());
            self::assertStringContainsString('is registered by this launch', $refused->content());

            $mine = $tool->withShellOwner('session-A')->execute(['shell_id' => $id]);
            self::assertFalse($mine->isError(), $mine->content());

            // The unbound launch-built tool keeps the old whole answer.
            self::assertFalse($tool->execute(['shell_id' => $id])->isError());
        } finally {
            $registry->teardown();
        }
    }

    public function testShellStopBoundToAnotherSessionRefusesWithoutTouchingTheShell(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $id = (string) $registry->start('sleep 120', 'A owns it', [], 'session-A')['shell']['id'];

            $tool = $this->stopToolFor($registry);
            $refused = $tool->withShellOwner('session-B')->execute(['shell_id' => $id]);
            self::assertTrue($refused->isError());
            self::assertStringContainsString('is registered by this launch', $refused->content());
            self::assertSame('running', $registry->status($id)['state'], 'a refused stop signals nothing');

            $mine = $tool->withShellOwner('session-A')->execute(['shell_id' => $id]);
            self::assertFalse($mine->isError(), $mine->content());
        } finally {
            $registry->teardown();
        }
    }

    public function testBashTagsItsBackgroundShellWithTheBoundOwner(): void
    {
        $registry = $this->scopedRegistry();
        try {
            $bash = (new Bash(root: $this->root, registry: $registry))->withShellOwner('session-A');
            $known = $registry->ids();

            $result = $bash->execute(['command' => 'true', 'description' => 'via Bash', 'run_in_background' => true]);
            self::assertFalse($result->isError(), $result->content());

            $fresh = array_values(array_diff($registry->ids(), $known));
            self::assertCount(1, $fresh);
            self::assertSame('session-A', $registry->status($fresh[0])['owner'], 'Bash claims the shell for its bound session');

            // An unbound Bash mints an untagged row — the launch-built tool
            // behaves exactly as it did before the tag.
            $plain = new Bash(root: $this->root, registry: $registry);
            $before = $registry->ids();
            self::assertFalse($plain->execute(['command' => 'true', 'description' => 'plain', 'run_in_background' => true])->isError());
            $unowned = array_values(array_diff($registry->ids(), $before));
            self::assertCount(1, $unowned);
            self::assertNull($registry->status($unowned[0])['owner']);
        } finally {
            $registry->teardown();
        }
    }

    public function testTheBoundOwnerSurvivesEveryRebuildOfEachShellTool(): void
    {
        self::assertContains(AcceptsShellOwner::class, array_keys(class_implements(Bash::class)), 'Bash implements the seam');
        self::assertInstanceOf(AcceptsShellOwner::class, ShellOutput::new());
        self::assertInstanceOf(AcceptsShellOwner::class, ShellStop::new());

        $bash = new Bash();
        self::assertNull($this->claimOf($bash), 'the launch-built claim is untagged');

        $bound = $bash->withShellOwner('s1');
        self::assertSame('s1', $this->claimOf($bound));
        self::assertNull($this->claimOf($bash), 'withShellOwner is a rebuild, not a mutation');

        // The order law the turn depends on: Bash implements BOTH seams, the
        // progress binding runs after the owner binding, and its rebuild
        // carries the claim — so a shell started mid-progress-stream is
        // still claimed. And the reverse order carries it too.
        $emitter = static function (): void {
        };
        self::assertSame('s1', $this->claimOf($bound->withToolProgressEmitter($emitter)));
        self::assertSame('s2', $this->claimOf($bash->withToolProgressEmitter($emitter)->withShellOwner('s2')));

        // The id-less static tools rebuild wholly through their two promoted
        // fields; the registry is carried and the claim set.
        $stopped = ShellStop::new()->withShellOwner('s3');
        self::assertSame('s3', $this->claimOf($stopped));
        $output = ShellOutput::new()->withShellOwner('s4');
        self::assertSame('s4', $this->claimOf($output));
    }

    public function testTurnToolsBindsTheShellOwnerBeforeTheArmsThatContinue(): void
    {
        $method = new \ReflectionMethod(\SugarCraft\Code\Backend\EngineBackend::class, 'turnTools');
        $lines = file((string) $method->getFileName());
        self::assertIsArray($lines);
        $body = implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        // The binding must sit INSIDE the tools loop, after the limits pass,
        // and before any arm that `continue`s past the rest of the loop —
        // the MutatesContextLedger and AcceptsToolProgress arms both do, and
        // Bash answers to the progress arm.
        $applied = strpos($body, 'applyTo($tool)');
        $bound = strpos($body, 'withShellOwner($this->sessionId)');
        $progressArm = strpos($body, 'instanceof \\SugarCraft\\Code\\Tools\\AcceptsToolProgress');
        self::assertNotFalse($applied);
        self::assertNotFalse($bound, 'the turn binds this session id into every shell tool');
        self::assertNotFalse($progressArm);
        self::assertLessThan($bound, $applied, 'the claim rides the per-turn tool copy, after the limits pass');
        self::assertLessThan($progressArm, $bound, 'binding after the progress arm would skip Bash entirely');
    }
}

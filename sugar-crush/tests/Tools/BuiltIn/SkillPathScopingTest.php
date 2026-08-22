<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * crush_feat.md section 7 E4: `SkillRegistry::getForPaths()` was correct and
 * tested but had no production caller, so touching a file a skill scopes
 * itself to surfaced nothing. Every assertion here fails against the old
 * Read/Edit/Glob, which had no way to reach the registry at all.
 *
 * @see SkillPathNudge
 */
final class SkillPathScopingTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-skill-paths-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function nudge(): SkillPathNudge
    {
        $registry = new SkillRegistry();
        $registry->register([
            'php-audit' => Skill::parse(
                <<<SKILL
                ---
                description: Security audit for PHP code
                paths:
                  - "*.php"
                ---
                body
                SKILL,
                'php-audit'
            ),
        ]);

        return SkillPathNudge::new($registry);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function testReadAppendsThePathScopedSkillReminderAfterTheFileContents(): void
    {
        $path = $this->write('App.php', "<?php\necho 1;\n");
        $tool = new Read(skillNudge: $this->nudge());

        $content = $tool->execute(['id' => 'c1', 'file_path' => $path])->content();

        self::assertStringStartsWith("<?php\necho 1;\n", $content);
        self::assertStringEndsWith(
            "<system-reminder>\n"
            . "These skills are scoped to paths you just touched. Invoke one with the Skill tool if it applies:\n"
            . "- php-audit: Security audit for PHP code\n"
            . '</system-reminder>',
            $content
        );
    }

    public function testReadLeavesAnUnscopedFileUntouched(): void
    {
        $path = $this->write('notes.txt', "plain\n");
        $tool = new Read(skillNudge: $this->nudge());

        self::assertSame("plain\n", $tool->execute(['id' => 'c1', 'file_path' => $path])->content());
    }

    public function testReadWithoutANudgeStaysByteIdentical(): void
    {
        $path = $this->write('App.php', "<?php\n");

        self::assertSame("<?php\n", (new Read())->execute(['id' => 'c1', 'file_path' => $path])->content());
    }

    public function testEditAppendsTheReminderAfterASuccessfulWrite(): void
    {
        $path = $this->write('App.php', "<?php\necho 'old';\n");
        $tool = new Edit(skillNudge: $this->nudge());

        $result = $tool->execute([
            'id' => 'c1',
            'file_path' => $path,
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        self::assertFalse($result->isError());
        self::assertStringContainsString('- php-audit: Security audit for PHP code', $result->content());
        // The reminder is informational only; it must never reach disk.
        self::assertStringNotContainsString('system-reminder', (string) file_get_contents($path));
    }

    public function testAFailedEditDoesNotBurnTheOneShotNudge(): void
    {
        $path = $this->write('App.php', "<?php\n");
        $nudge = $this->nudge();
        $tool = new Edit(skillNudge: $nudge);

        $failed = $tool->execute([
            'id' => 'c1',
            'file_path' => $path,
            'old_string' => 'nowhere',
            'new_string' => 'x',
        ]);

        self::assertTrue($failed->isError());
        self::assertSame([], $nudge->announced());
        self::assertStringContainsString('- php-audit:', (string) $nudge->forPath($path));
    }

    public function testGlobAppendsOneReminderForTheWholeMatchList(): void
    {
        $this->write('A.php', '');
        $this->write('B.php', '');
        $tool = new Glob(skillNudge: $this->nudge());

        $content = $tool->execute([
            'id' => 'c1',
            'pattern' => '*.php',
            'path' => $this->dir,
        ])->content();

        self::assertStringContainsString('/A.php', $content);
        self::assertSame(1, substr_count($content, '<system-reminder>'));
    }

    public function testASharedNudgeAnnouncesASkillOnlyOnceAcrossReadEditAndGlob(): void
    {
        $path = $this->write('App.php', "<?php\necho 'old';\n");
        $nudge = $this->nudge();

        $read = (new Read(skillNudge: $nudge))->execute(['id' => 'c1', 'file_path' => $path]);
        $glob = (new Glob(skillNudge: $nudge))->execute(['id' => 'c2', 'pattern' => '*.php', 'path' => $this->dir]);
        $edit = (new Edit(skillNudge: $nudge))->execute([
            'id' => 'c3',
            'file_path' => $path,
            'old_string' => 'old',
            'new_string' => 'new',
        ]);

        self::assertStringContainsString('system-reminder', $read->content());
        self::assertStringNotContainsString('system-reminder', $glob->content());
        self::assertStringNotContainsString('system-reminder', $edit->content());
        self::assertSame(['php-audit'], $nudge->announced());
    }
    // =========================================================================
    // What the byte reservations actually reserve (E71)
    // =========================================================================

    /**
     * E71: {@see Read::execute()} calls the nudge's share "an eighth" of
     * $maxBytes and nothing pinned it. MEASURED at ae30fee5, changing
     * `intdiv($this->maxBytes, 8)` to `intdiv($this->maxBytes, 2)` survived the
     * whole 8,909-test suite.
     *
     * Pinned as a THRESHOLD, and the far side of it is DERIVED: the smallest
     * budget the tracker will spend on one entry is asked of the tracker
     * itself, so this survives any change to HEADER, FOOTER or
     * MAX_ENTRY_BYTES and fails only if Read stops handing over exactly an
     * eighth. Two-sided, because "the nudge appears at N" alone is satisfied
     * by every divisor smaller than 8, and "it does not appear at N-1" alone
     * by every divisor larger.
     */
    public function testReadSpendsExactlyAnEighthOfMaxBytesOnTheSkillNudge(): void
    {
        $path = $this->write('App.php', "<?php\n");

        $floor = $this->smallestNudgeBudget($path);
        $threshold = 8 * $floor;

        self::assertStringContainsString(
            '<system-reminder>',
            $this->readAt($path, $threshold),
            "maxBytes {$threshold}: an eighth is exactly the {$floor}-byte floor, so the nudge fits",
        );
        self::assertStringNotContainsString(
            '<system-reminder>',
            $this->readAt($path, $threshold - 1),
            'one byte lower, an eighth is one byte short of the floor and the nudge must be withheld',
        );
    }

    /**
     * E71: `$nudgeCost = ... strlen($nudge) + 1` in {@see Glob::execute()}
     * reserves the newline the nudge is appended behind. MEASURED at ae30fee5,
     * dropping the `+ 1` survived the whole suite — and it is a REACHABLE
     * one-byte overrun, not a theoretical one: it shows on the single cap
     * where `truncateOutput()` hands the path list back saturating $bodyCap
     * exactly, and on no other.
     *
     * That cap is DERIVED here rather than named, because it moves with the
     * length of `sys_get_temp_dir()` and of the random fixture directory —
     * writing it down would pin this box's tmp path, not the reservation. The
     * sweep is deliberately a WINDOW: the unmutated saturation and the
     * mutated overrun are one byte apart, so a single probe at either one
     * misses the other.
     */
    public function testGlobsNudgeReservationHoldsTheResultInsideTheCapAtSaturation(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->write(sprintf('f%02d.php', $i), '');
        }

        $uncapped = $this->globAt(0);
        self::assertStringContainsString(
            '<system-reminder>',
            $uncapped,
            'the fixture must produce a nudge, or there is no reservation under test',
        );

        // The smallest cap the whole result fits inside with nothing to spare.
        // One byte under it the list must be cut, and the cut lands on
        // $bodyCap exactly — which is the only place the missing +1 shows.
        $exact = strlen($uncapped);

        $sawSaturation = false;
        for ($cap = $exact - 8; $cap <= $exact + 8; $cap++) {
            $length = strlen($this->globAt($cap));
            self::assertLessThanOrEqual(
                $cap,
                $length,
                "cap {$cap}: the result overran its own cap by " . ($length - $cap) . ' byte(s)',
            );
            $sawSaturation = $sawSaturation || $length === $cap;
        }

        self::assertTrue(
            $sawSaturation,
            'the window must contain the cap the body saturates exactly, or the sweep never reached '
            . 'the boundary the reserved byte exists for',
        );
    }

    // =========================================================================
    // What "pending" means (E72)
    // =========================================================================

    /**
     * E72: {@see SkillPathNudge::hasPending()} did not consult
     * `isAutoInvocable()`, so a path-scoped skill carrying
     * `disable-model-invocation: true` was pending forever. DRIVEN at
     * ae30fee5: two consecutive `forPath()` calls both returned null,
     * `announced()` stayed empty, and `hasPending()` was still true after
     * both — meaning every tool call for the rest of the session walked the
     * registry and ran `fnmatch()` per pattern per path to rediscover that
     * there was nothing to say.
     *
     * The predicate is reached by reflection because it is the thing that was
     * wrong and it has no external observable: {@see forPaths()} returns null
     * either way. The externally visible half is asserted beside it so this is
     * not a white-box test alone.
     */
    public function testASkillTheModelMayNotInvokeIsNotPending(): void
    {
        $path = $this->write('App.php', "<?php\n");
        $nudge = SkillPathNudge::new($this->registryOf([
            'user-only' => ['Reserved for the operator', true],
        ]));

        self::assertNull($nudge->forPath($path), 'a user-only skill must never be nudged at the model');
        self::assertSame([], $nudge->announced());
        self::assertFalse(
            (new \ReflectionMethod(SkillPathNudge::class, 'hasPending'))->invoke($nudge),
            'a skill forPaths() refuses to announce must not keep the guard open, or the short-circuit '
            . 'the class doc-block promises never fires again this session',
        );
    }

    /**
     * The steady state the class doc-block claims — "a long session pays
     * nothing per tool call" — has to actually ARRIVE in a tree that mixes the
     * two kinds of skill, which is the ordinary shape: one auto-invocable
     * `*.php` skill beside one the operator reserved for themselves.
     */
    public function testTheGuardClosesOnceEveryAnnounceableSkillIsAnnounced(): void
    {
        $path = $this->write('App.php', "<?php\n");
        $nudge = SkillPathNudge::new($this->registryOf([
            'php-audit' => ['Security audit for PHP code', false],
            'user-only' => ['Reserved for the operator', true],
        ]));
        $hasPending = new \ReflectionMethod(SkillPathNudge::class, 'hasPending');

        self::assertTrue($hasPending->invoke($nudge), 'php-audit has not been announced yet');
        self::assertStringContainsString('php-audit', (string) $nudge->forPath($path));
        self::assertSame(['php-audit'], $nudge->announced(), 'the user-only skill must not be marked');
        self::assertFalse(
            $hasPending->invoke($nudge),
            'nothing announceable is left, so the guard must close even though user-only is unannounced',
        );
    }

    private function readAt(string $path, int $maxBytes): string
    {
        return (new Read(maxBytes: $maxBytes, skillNudge: $this->nudge()))
            ->execute(['id' => 'c1', 'file_path' => $path])
            ->content();
    }

    private function globAt(int $maxOutputBytes): string
    {
        return (new Glob($this->dir, skillNudge: $this->nudge(), maxOutputBytes: $maxOutputBytes))
            ->execute(['id' => 'c1', 'pattern' => '*.php', 'path' => $this->dir])
            ->content();
    }

    /**
     * The smallest $budget {@see SkillPathNudge::forPaths()} will spend on one
     * entry for $path, asked of the tracker rather than recomputed here. A
     * recomputation would be a second copy of its pricing, and the two would
     * agree right up until one of them changed.
     */
    private function smallestNudgeBudget(string $path): int
    {
        for ($budget = 1; $budget <= SkillPathNudge::maxBytes(); $budget++) {
            if ($this->nudge()->forPath($path, $budget) !== null) {
                return $budget;
            }
        }

        self::fail('no budget up to the class ceiling produced a nudge');
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $skills name => [description, disableModelInvocation]
     */
    private function registryOf(array $skills): SkillRegistry
    {
        $registry = new SkillRegistry();
        $parsed = [];
        foreach ($skills as $name => [$description, $userOnly]) {
            $parsed[$name] = Skill::parse(
                "---\ndescription: {$description}\n"
                . ($userOnly ? "disable-model-invocation: true\n" : '')
                . "paths:\n  - \"*.php\"\n---\nbody\n",
                $name
            );
        }
        $registry->register($parsed);

        return $registry;
    }
}

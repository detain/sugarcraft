<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Hooks\ClaudeSettingsHooks;
use SugarCraft\Code\Tests\Support\SlicesDeclaredMethodsTrait;

/**
 * v1:F-1, the RECORD half: an unknown event in a foreign Claude Code
 * settings file is skipped by the parser
 * ({@see \SugarCraft\Code\Tests\Hooks\ClaudeSettingsHooksTest}) and drained
 * onto the launch-notice seam by
 * {@see Bootstrap::reportSkippedClaudeSettingsEvents()} — the record that
 * keeps "skip" from becoming the silent-gap failure R3 refused to allow.
 *
 * The three polarities the remediation is graded on:
 *  - foreign file, unknown event → launch proceeds AND a row names it;
 *  - candy-code's own native hooks.yaml dialect → hard refusal unchanged
 *    (pinned where the parser hands off: HookConfig, see the hooks suite);
 *  - foreign file, known event → loads (pinned by the flatten-equality test).
 */
final class ClaudeSettingsHooksSkipRecordTest extends TestCase
{
    use SlicesDeclaredMethodsTrait;

    protected function setUp(): void
    {
        ClaudeSettingsHooks::resetSkippedEventsForTesting();
        $this->resetNoticeStatics();
    }

    protected function tearDown(): void
    {
        ClaudeSettingsHooks::resetSkippedEventsForTesting();
        $this->resetNoticeStatics();
    }

    public function testAnEmptyRecordDrainsNothing(): void
    {
        Bootstrap::reportSkippedClaudeSettingsEvents();

        $this->assertSame([], Bootstrap::launchNotices(), 'no skip, no row — a launch that met no unknown events stays byte-unchanged');
    }

    public function testTheDrainNamesEverySkippedEventOnceInOneAggregateRow(): void
    {
        ClaudeSettingsHooks::parse(
            '{"hooks":{"Notification":[{"hooks":[{"type":"command","command":"./a.sh"}]}],"StopFailure":[{"hooks":[{"type":"command","command":"./b.sh"}]}]}}',
            '/home/user/.claude/settings.json',
        );
        // A second file skipping a THIRD event adds one more name to the
        // SAME row and does not mint a second row.
        ClaudeSettingsHooks::parse(
            '{"hooks":{"PostToolUseFailure":[{"hooks":[{"type":"command","command":"./c.sh"}]}]}}',
            '/proj/.claude/settings.local.json',
        );

        Bootstrap::reportSkippedClaudeSettingsEvents();

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString("'Notification', 'StopFailure', 'PostToolUseFailure'", $notices[0]);
        $this->assertStringContainsString('3 hook group(s) across 2 file(s)', $notices[0]);
        $this->assertStringContainsString('were skipped', $notices[0]);
        $this->assertStringNotContainsString(
            'is not a hook event',
            $notices[0],
            'the row is the record — the native validator never fired for these events',
        );
    }

    public function testTheDrainRunsFromChatAndNothingElseNeedsToKnow(): void
    {
        $bootstrapFile = \dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php';
        $lines = file($bootstrapFile);
        // P-TABS.d: the skip-record drain moved into buildChat() with the
        // extraction (launch-gated, so tabChat() never reaches it).
        $method = new \ReflectionMethod(Bootstrap::class, 'buildChat');
        // E325 direction one (plan_comparison W4 weld): reflection's line
        // numbers address the file the method is DECLARED in while the scrape
        // reads one named path. True by construction today; asserted so a
        // future extraction of chat() into a trait cannot silently scrape
        // unrelated lines — the defect the reader census exists to catch.
        $this->assertSame(
            $bootstrapFile,
            $method->getFileName(),
            'buildChat() is no longer declared in ' . $bootstrapFile . '; read the body from'
                . ' $method->getFileName() or keep the drain where it is.',
        );
        $slice = self::declaredSlice($lines, 'buildChat', $method->getStartLine(), $method->getEndLine());

        $this->assertSame(
            1,
            substr_count($slice, 'self::reportSkippedClaudeSettingsEvents();'),
            'chat() builds through buildChat(), which must drain the skip record exactly once — every other launch mode builds the same way',
        );
    }

    public function testTheRepeatDrainAddsNothingBecauseTheRowIsStable(): void
    {
        ClaudeSettingsHooks::parse(
            '{"hooks":{"Notification":[{"hooks":[{"type":"command","command":"./a.sh"}]}]}}',
            '/home/user/.claude/settings.json',
        );

        Bootstrap::reportSkippedClaudeSettingsEvents();
        Bootstrap::reportSkippedClaudeSettingsEvents();

        $this->assertCount(1, Bootstrap::launchNotices(), 'the seam de-dupes by message identity, so a re-drain costs nothing');
    }

    /**
     * C-EXT-1.b widened the SAME one row: entries of an unsupported type and
     * entries carrying an off-dialect key join the skipped event names in
     * naming what contributed no guard. One parse, one drain, still exactly
     * one aggregate row — the launch-cap census entry stays 1.
     */
    public function testTheAggregateNamesSkippedEntryTypesAndKeysBesideEvents(): void
    {
        ClaudeSettingsHooks::parse(
            '{"hooks":{"Notification":[{"hooks":[{"type":"command","command":"./a.sh"}]}],'
            . '"PreToolUse":[{"hooks":[{"type":"prompt","command":"be smart"},'
            . '{"type":"command","command":"./gate.sh","if":"Bash(ls)"}]}]}}',
            '/home/user/.claude/settings.json',
        );

        Bootstrap::reportSkippedClaudeSettingsEvents();

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString("no candy-code hook event matches 'Notification'", $notices[0]);
        $this->assertStringContainsString("typed 'prompt'", $notices[0]);
        $this->assertStringContainsString("carrying 'if'", $notices[0]);
        // C-EXT-1.b round 2 (rv1 MINOR): the figure was '3' here, because
        // the single PreToolUse group carried TWO names ('prompt' and 'if')
        // and the old row summed the per-name counters.  Two physical groups
        // contributed no guard in this file — the Notification one and the
        // PreToolUse one — and that is what the row now says.
        $this->assertStringContainsString('2 hook group(s) across 1 file(s)', $notices[0]);
        $this->assertStringContainsString('were skipped', $notices[0]);
    }

    /**
     * THE discriminating leg for the round-2 fix: one group carrying two
     * DIFFERENT skips is one group in the aggregate.  The per-name records
     * keep their original meaning (how often that name was skipped, summed
     * to two here) — the launch row is built from
     * {@see ClaudeSettingsHooks::skippedGroups()}, the distinct
     * file+event+group census, so it cannot double-count.
     */
    public function testAGroupCarryingTwoDifferentSkipsCountsOnceInTheAggregate(): void
    {
        ClaudeSettingsHooks::parse(
            '{"hooks":{"PreToolUse":[{"hooks":[{"type":"prompt","command":"be smart"},'
            . '{"type":"command","command":"./gate.sh","if":"Bash(ls)"}]}]}}',
            '/home/user/.claude/settings.json',
        );

        $entries = ClaudeSettingsHooks::skippedEntries();
        $this->assertSame(['prompt', 'if'], array_keys($entries));
        $this->assertSame(
            2,
            $entries['prompt']['groups'] + $entries['if']['groups'],
            'the per-name tally still counts skips (it is a diagnostic, not the row)',
        );
        $this->assertCount(1, ClaudeSettingsHooks::skippedGroups(), 'the census counts the group once');

        Bootstrap::reportSkippedClaudeSettingsEvents();

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString(', so 1 hook group(s) across 1 file(s) ', $notices[0]);
    }

    /**
     * The other polarity of the same census: two groups carrying the SAME
     * skip name in the same file are two groups — the fix dedupes physical
     * groups, it does not collapse the announcement into one per name.
     */
    public function testTwoGroupsCarryingTheSameSkipStillCountAsTwoGroups(): void
    {
        ClaudeSettingsHooks::parse(
            '{"hooks":{"PreToolUse":[{"hooks":[{"type":"prompt","command":"be smart"}]},'
            . '{"matcher":"Bash","hooks":[{"type":"prompt","command":"be smarter"}]}]}}',
            '/home/user/.claude/settings.json',
        );

        Bootstrap::reportSkippedClaudeSettingsEvents();

        $notices = Bootstrap::launchNotices();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString(', so 2 hook group(s) across 1 file(s) ', $notices[0]);
        $this->assertStringContainsString("typed 'prompt'", $notices[0]);
    }

    private function resetNoticeStatics(): void
    {
        foreach ([
            'launchNotices' => [],
            'launchNoticesDropped' => [],
            'reportedPermissionConfigWarnings' => [],
        ] as $name => $value) {
            $property = new \ReflectionProperty(LaunchNotices::class, $name);
            $property->setAccessible(true);
            $property->setValue(null, $value);
        }
    }
}

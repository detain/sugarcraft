<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandShellPosture;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Commands\ShellProvenance;
use SugarCraft\Code\Skills\SkillSource;

/**
 * C-SEC-3 (plan_comparison §6): the (tier, source) -> provenance table is the
 * single answer both the `!cmd` refusal and the fork decision read, so the
 * whole table is pinned row by row here — including the two orderings the
 * prose states: the `mcp` tier stamp answers first, and a bundle badge
 * OUTRANKS the tier it installed under.
 */
final class CommandShellPostureTest extends TestCase
{
    public function testTheMcpTierStampAnswersMcpWhateverElseTheRowCarries(): void
    {
        $row = new CommandSpec('mcp__srv__p', 'd', 'Custom', template: 'run', tier: 'mcp');
        self::assertSame(ShellProvenance::Mcp, CommandShellPosture::of($row));

        // Even a row carrying both third-party marks reads as the fetched body
        // — the tier is a stamp no disk walk applies.
        $both = new CommandSpec('mcp__srv__p', 'd', 'Custom', template: 'run', tier: 'mcp', source: SkillSource::Bundle);
        self::assertSame(ShellProvenance::Mcp, CommandShellPosture::of($both));
    }

    public function testTheBundleBadgeOutranksTheTierItInstalledUnder(): void
    {
        // ~/.candy-code/bundles rows install at user tier…
        $userBundle = new CommandSpec('b', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::Bundle);
        self::assertSame(ShellProvenance::Bundle, CommandShellPosture::of($userBundle));

        // …and checkout-anchored bundles at project tier. Both are refused;
        // the tier alone would read either as native text this machine chose.
        $projectBundle = new CommandSpec('b', 'd', 'Custom', template: 'run', tier: 'project', source: SkillSource::Bundle);
        self::assertSame(ShellProvenance::Bundle, CommandShellPosture::of($projectBundle));
    }

    public function testAProjectTierNativeRowIsTheRepositorys(): void
    {
        $row = new CommandSpec('p', 'd', 'Custom', template: 'run', tier: 'project');
        self::assertSame(ShellProvenance::Repo, CommandShellPosture::of($row));
    }

    public function testAUserTierNativeRowIsTheOperators(): void
    {
        $row = new CommandSpec('u', 'd', 'Custom', template: 'run', tier: 'user');
        self::assertSame(ShellProvenance::Operator, CommandShellPosture::of($row));
    }

    public function testAnUnTieredSpecReadsAsTheOperators(): void
    {
        // In-process callers (and every built-in) build specs with no tier;
        // the gate stays the only check, exactly as before C-SEC-3.
        $row = new CommandSpec('x', 'd', 'Custom', template: 'run');
        self::assertSame(ShellProvenance::Operator, CommandShellPosture::of($row));
    }

    /**
     * Foreign rows keep the tier's answer — the C-SEC-3.b ruling settles it:
     * the imported tree's provenance badge does not change the posture, and
     * `docs/COMMANDS.md`'s foreign row now states what this pin enforces.
     */
    public function testForeignRowsAreJudgedByTheirTierNotTheirBadge(): void
    {
        $claudeUser = new CommandSpec('c1', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::Claude);
        self::assertSame(ShellProvenance::Operator, CommandShellPosture::of($claudeUser));

        $opencodeUser = new CommandSpec('c2', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::Opencode);
        self::assertSame(ShellProvenance::Operator, CommandShellPosture::of($opencodeUser));

        $claudeProject = new CommandSpec('c3', 'd', 'Custom', template: 'run', tier: 'project', source: SkillSource::Claude);
        self::assertSame(ShellProvenance::Repo, CommandShellPosture::of($claudeProject));

        $specRow = new CommandSpec('c4', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::AgentSkillsSpec);
        self::assertSame(ShellProvenance::Operator, CommandShellPosture::of($specRow));
    }

    public function testOnlyTheTwoThirdPartyProvenancesRefuseShell(): void
    {
        self::assertTrue(ShellProvenance::Mcp->refusesShell());
        self::assertTrue(ShellProvenance::Bundle->refusesShell());
        self::assertFalse(ShellProvenance::Operator->refusesShell(), 'the operator\'s own file is the gate\'s business, not a refusal\'s');
        self::assertFalse(ShellProvenance::Repo->refusesShell(), 'a repository row is unlocked by trustedProjectCommands, not refused outright');
    }

    public function testTheTableCoversEveryProvenanceWithAnAnswer(): void
    {
        // Fail-closed shape: a NEW enum case must not silently read as
        // "does not refuse" — add it to this roster deliberately.
        $refusing = [];
        foreach (ShellProvenance::cases() as $case) {
            if ($case->refusesShell()) {
                $refusing[] = $case->name;
            }
        }
        self::assertSame(['Bundle', 'Mcp'], $refusing);
    }
}

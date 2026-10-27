<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Host\TurnController;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Skills\SkillSource;

/**
 * C-SEC-3 (plan_comparison §6): a plugin bundle's command rows are a package
 * somebody else packed — their `` !`…` `` forms are refused outright, before
 * the trust key and before the permission gate, whatever tier the bundle
 * installed under; and because the refusal lands first, a bundle row must
 * NEVER fork on a shell form's account (the child would only run the refusal
 * off-thread). The same pins hold the untouched postures: the operator's own
 * file and foreign rows keep today's tier-decides answer.
 */
final class TurnControllerBundleShellTest extends TestCase
{
    public function testABundleShellFormIsRefusedBeforeTrustAndBeforeTheGate(): void
    {
        $turns = TurnController::new();
        $bundle = CommandSpec::new('b', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::Bundle);
        $asked = [];
        $record = static function (string $command) use (&$asked): void {
            $asked[] = $command;
        };
        $gate = new PermissionGate(PermissionMode::Default);

        $refusal = $turns->refuseCommandShell($bundle, 'make test', false, $gate, $record);
        self::assertNotNull($refusal);
        self::assertStringContainsString('came from a plugin bundle', $refusal);
        self::assertStringNotContainsString('came from this project', $refusal, 'the bundle names its own provenance, not the repository sentence');
        self::assertSame([], $asked, 'a posture refusal never moves the gate');

        // The flip against MCP-2-era behavior: the project-trust key unlocks
        // repository files, and it unlocks nothing here…
        self::assertNotNull($turns->refuseCommandShell($bundle, 'make test', true, $gate, $record), 'C-SEC-3: trustedProjectCommands does not unlock a bundle row');
        // …and neither does the absence of a gate — the refusal lands before
        // the gate would have been consulted.
        self::assertNotNull($turns->refuseCommandShell($bundle, 'make test', true, null), 'a bundle shell form is refused even where no gate exists');
        self::assertSame([], $asked);
    }

    public function testABundleRowIsRefusedUnderTheProjectTierToo(): void
    {
        // A checkout-anchored bundle installs at project tier; the badge
        // outranks the tier, so the project sentence does not apply either.
        $turns = TurnController::new();
        $bundle = CommandSpec::new('b', 'd', 'Custom', template: 'run', tier: 'project', source: SkillSource::Bundle);

        $refusal = $turns->refuseCommandShell($bundle, 'make test', false, null);
        self::assertNotNull($refusal);
        self::assertStringContainsString('came from a plugin bundle', $refusal);
        self::assertStringNotContainsString('trustedProjectCommands', $refusal, 'the bundle sentence must not point at the very grant that unlocks nothing for it');
    }

    public function testTheOperatorsOwnRowAndAForeignRowStillReachTheGate(): void
    {
        // Today-behavior, untouched by C-SEC-3: a user-tier native row is the
        // operator's own file, and a user-tier foreign import is judged by
        // the tier exactly like it was (C-SEC-3.b will rule on foreign).
        $turns = TurnController::new();
        $native = CommandSpec::new('u', 'd', 'Custom', template: 'run', tier: 'user');
        $foreign = CommandSpec::new('c', 'd', 'Custom', template: 'run', tier: 'user', source: SkillSource::Claude);
        $asked = [];
        $record = static function (string $command) use (&$asked): void {
            $asked[] = $command;
        };
        $gate = new PermissionGate(PermissionMode::Default);

        self::assertNull($turns->refuseCommandShell($native, 'make test', false, $gate, $record), 'an Ask proceeds for the operator\'s own file');
        self::assertNull($turns->refuseCommandShell($foreign, 'make test', false, $gate, $record), 'a user-tier foreign row keeps today\'s gate-only answer');
        self::assertSame(['make test', 'make test'], $asked);
    }

    public function testABundleRowNeverForksForItsShellFormsAndTheOtherPosturesKeepTodaysAnswer(): void
    {
        $turns = TurnController::new();
        $bundle = CommandSpec::new('twig', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'user', source: SkillSource::Bundle);
        $native = CommandSpec::new('twig', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'user');
        $project = CommandSpec::new('twig', 'd', 'Custom', template: 'On !`printf %s twig` now.', tier: 'project');
        $commands = ['twig' => $bundle];

        // Refused posture -> nothing to run off-thread -> no fork, trusted or not.
        self::assertFalse(
            $turns->customCommandMustFork('/twig x', $commands, null, true),
            'a bundle row forks for no shell — its only shell forms expand to refusals',
        );
        $commands['twig'] = $native;
        self::assertTrue(
            $turns->customCommandMustFork('/twig x', $commands, null, false),
            'the operator\'s own shell row still forks off-thread',
        );
        $commands['twig'] = $project;
        self::assertFalse(
            $turns->customCommandMustFork('/twig x', $commands, null, false),
            'an untrusted repository row expands its refusal inline — no fork',
        );
        self::assertTrue(
            $turns->customCommandMustFork('/twig x', $commands, null, true),
            'a trusted repository row keeps today\'s off-thread shell',
        );
    }
}

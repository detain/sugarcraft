<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Host\Commands\BudgetHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\SpendLedger;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Role;
use SugarCraft\Code\Util\TokenTracker;

/**
 * `/budget` over the wire (C-WEB-2.e): the read-only half of the TUI command.
 *
 * The bare form is the whole product — spend against the launch cap, with the
 * SES-1 lower-bound law (an unpriced session reads `≥`, never a bare figure:
 * the audit-era defect family was the two surfaces meaning the SAME number
 * differently, and a settled-looking floor is exactly that mistake). The
 * setting forms refuse out loud, because on a headless host the cap belongs
 * to the launch (`--max-cost`) and every session shares it: a set that read
 * as accepted but did nothing would be the worse crime than the refusal.
 */
final class BudgetHostCommandTest extends TestCase
{
    private function reply(CommandContext $context, string $draft): string
    {
        return (new BudgetHostCommand())->run($context, $draft)->rows[1]->content;
    }

    public function testTheBudgetSpellingCarriesTheHostBody(): void
    {
        $command = BuiltInCommands::forSpelling('budget');

        $this->assertNotNull($command);
        $this->assertSame(BudgetHostCommand::class, $command->hostCommand);
        $this->assertInstanceOf(BudgetHostCommand::class, $command->instantiateHostCommand());
    }

    public function testTheBareFormShowsSpendAgainstTheLaunchCap(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(1000, 500, 0.42);

        $reply = $this->reply(
            CommandContext::new(spendTracker: $tracker, spendCap: 5.0),
            '/budget',
        );

        $this->assertStringContainsString('Spend so far: $0.4200', $reply);
        $this->assertStringContainsString('cap $5.0000', $reply);
        $this->assertStringNotContainsString('≥', $reply, 'a fully priced session is a measurement, not a bound');
    }

    public function testAnUnpricedSessionReadsALowerBound(): void
    {
        $tracker = new TokenTracker();
        $tracker->addUsage(10, 5, 0.01);
        $tracker->noteUnpricedUsage();

        $reply = $this->reply(CommandContext::new(spendTracker: $tracker, spendCap: 2.0), '/budget');

        $this->assertStringContainsString('Spend so far: ≥$0.0100', $reply, 'the bound rides the number itself');
        $this->assertStringContainsString(Lang::t('host.spend.lower_bound'), $reply);
    }

    public function testAnAmountFormRefusesWithTheServerCapReasonAndLeavesTheCapAlone(): void
    {
        $workspace = WorkspaceContext::new(maxCostUsd: 5.0);
        $context = CommandContext::new(workspace: $workspace, spendCap: 5.0);

        $result = (new BudgetHostCommand())->run($context, '/budget 7');

        $this->assertCount(2, $result->rows);
        $this->assertSame(Role::User, $result->rows[0]->role);
        $this->assertSame(
            Lang::t('host.budget.server_cap', ['requested' => '7']),
            $result->rows[1]->content,
            'the refusal says WHY and where the cap actually lives',
        );
        $this->assertSame([], $result->effects, 'a refusal carries no state channel at all — there is nothing that could move the cap');
        $this->assertSame(5.0, $workspace->maxCostUsd, 'the shared launch cap is untouched');
        $this->assertSame(5.0, $context->spendCap);

        // The same command on a context that reads the workspace must answer
        // identically: $7 against $5 would print "over cap" if the set landed.
        $this->assertStringNotContainsString('cap set', strtolower($this->reply($context, '/budget 7')));
    }

    public function testTheOffFormRefusesWithTheSameReason(): void
    {
        $reply = $this->reply(CommandContext::new(spendCap: 5.0), '/budget off');

        $this->assertSame(Lang::t('host.budget.server_cap', ['requested' => 'off']), $reply);
    }

    public function testADollarSpellingRefusesToo(): void
    {
        $reply = $this->reply(CommandContext::new(spendCap: 5.0), '/budget $2.50');

        $this->assertSame(Lang::t('host.budget.server_cap', ['requested' => '$2.50']), $reply, 'a leading dollar is still a set attempt');
    }

    public function testAnythingElseGetsTheUsageLineWithTheCapLeftAlone(): void
    {
        $workspace = WorkspaceContext::new(maxCostUsd: 5.0);

        foreach (['banana', '0', '1e309', '-4'] as $argument) {
            $reply = $this->reply(
                CommandContext::new(workspace: $workspace, spendCap: 5.0),
                '/budget ' . $argument,
            );
            $this->assertSame(Lang::t('host.budget.usage'), $reply, '/budget ' . $argument . ' is not an amount or a clear');
        }

        $this->assertSame(5.0, $workspace->maxCostUsd);
    }

    public function testAContextWithoutATrackerReportsUnreportedRatherThanZero(): void
    {
        $reply = $this->reply(CommandContext::new(), '/budget');

        $this->assertStringContainsString('not reported', $reply);
        $this->assertStringContainsString(Lang::t('host.spend.no_cap'), $reply);
        $this->assertStringNotContainsString('$0.0000', $reply, 'an unmeasured session never prints a settled zero');
    }

    public function testTheWorkspaceRegisteredLedgerIsTheOneThatAnswers(): void
    {
        $ledger = SpendLedger::new();
        $workspace = WorkspaceContext::new()->withService(SpendLedger::class, $ledger);

        $tracker = new TokenTracker();
        $tracker->addUsage(4, 2, 0.03);

        $this->assertSame(
            $ledger->statusLine($tracker, 9.0),
            $this->reply(CommandContext::new(workspace: $workspace, spendTracker: $tracker, spendCap: 9.0), '/budget'),
            'the status half reads the workspace service, exactly as SessionHost::ledger() chooses it',
        );
    }
}

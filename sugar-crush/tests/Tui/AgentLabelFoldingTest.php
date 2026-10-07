<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Events\SubAgentActivity;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentLabelFolding;
use SugarCraft\Crush\Tui\Components\AgentDashboardPane;
use SugarCraft\Crush\Tui\Components\AgentSplitColumn;

/**
 * CL-2 FIX 4 — concurrent labels sharing a >= 2-word prompt prefix fold to
 * `"<head two words> … <first differing word (+1)>"` so the difference
 * survives the 30-cell clip. Everything else stays byte-identical, rows
 * never move, and no click key is touched.
 */
final class AgentLabelFoldingTest extends TestCase
{
    public function testFewerThanTwoLabelsNeverFold(): void
    {
        $this->assertSame([], AgentLabelFolding::fold([]));
        $this->assertSame(
            ['You are auditing candy-core'],
            AgentLabelFolding::fold(['You are auditing candy-core']),
        );
    }

    public function testASingleWordPrefixStaysByteIdentical(): void
    {
        // The shape pinned live: 'Audit candy-core' / 'Audit candy-ansi'.
        $labels = ['Audit candy-core', 'Audit candy-ansi', 'Audit candy-layout'];

        $this->assertSame($labels, AgentLabelFolding::fold($labels));
    }

    public function testTwoWordPrefixesActivateTheFold(): void
    {
        $folded = AgentLabelFolding::fold([
            'You are auditing one library in sugar-dash',
            'You are auditing one library in candy-mosaic',
        ]);

        $this->assertSame(
            ['You are … sugar-dash', 'You are … candy-mosaic'],
            $folded,
        );
    }

    public function testTheTailCarriesUpToTwoWordsFromTheDifference(): void
    {
        $folded = AgentLabelFolding::fold([
            'Repair the flaky suite in candy-pty now please',
            'Repair the flaky suite in sugar-mosaic now please',
        ]);

        // Shared prefix is 5 words; the tail starts AT the difference.
        $this->assertSame(
            ['Repair the … candy-pty now', 'Repair the … sugar-mosaic now'],
            $folded,
        );
    }

    public function testIdenticalLabelsNeverFold(): void
    {
        $labels = ['You are auditing sugar-dash', 'You are auditing sugar-dash'];

        $this->assertSame($labels, AgentLabelFolding::fold($labels));
    }

    public function testAPrefixOnlyMemberHoldsTheWholeGroupPlain(): void
    {
        // 'You are' has no remainder — the group cannot be disambiguated
        // honestly, so nothing folds.
        $labels = ['You are', 'You are auditing sugar-dash'];

        $this->assertSame($labels, AgentLabelFolding::fold($labels));
    }

    public function testAGroupThatWouldNotShrinkStaysPlain(): void
    {
        $folded = AgentLabelFolding::fold(['ab cd e', 'ab cd f']);

        $this->assertSame(['ab cd e', 'ab cd f'], $folded, '"ab cd … e" is longer than "ab cd e"');
    }

    public function testTheFoldSurvivesTheThirtyCellClipWithItsDifference(): void
    {
        $plain = [
            'You are auditing one very long shared prefix across many words in sugar-dash',
            'You are auditing one very long shared prefix across many words in candy-mosaic',
        ];
        $folded = AgentLabelFolding::fold($plain);

        foreach ([['sugar-dash', 0], ['candy-mosaic', 1]] as [$needle, $i]) {
            $this->assertLessThanOrEqual(
                30,
                Width::string($folded[$i]),
                "the folded label #$i fits the tightest budget un-truncated",
            );
            $this->assertStringContainsString($needle, Width::truncate($folded[$i], 30));
            $this->assertStringNotContainsString(
                $needle,
                Width::truncate($plain[$i], 30),
                'the unfold clipped the difference away — that is the bug being folded',
            );
        }
    }

    public function testFoldingIsOrderInvariantPerLabel(): void
    {
        $labels = [
            'You are auditing one library in sugar-dash',
            'You are auditing one library in candy-mosaic',
            'You are auditing one library in honey-bounce',
        ];
        $forward = AgentLabelFolding::fold($labels);

        $reverse = AgentLabelFolding::fold(array_reverse($labels));
        $this->assertSame(array_reverse($forward), $reverse);

        $rotated = AgentLabelFolding::fold([$labels[1], $labels[2], $labels[0]]);
        $this->assertSame([$forward[1], $forward[2], $forward[0]], $rotated);
    }

    public function testOnlyCollidingGroupsFold(): void
    {
        $folded = AgentLabelFolding::fold([
            'You are auditing one library in sugar-dash',
            'You are auditing one library in candy-mosaic',
            'Summarize the changelog for release day',
        ]);

        $this->assertSame('Summarize the changelog for release day', $folded[2]);
    }

    public function testApplyRewritesOperationsPositionally(): void
    {
        $states = [
            AgentDisplayState::new(name: 'a', status: 'working', operation: 'You are probing one in alpha', elapsedSeconds: 0, tokensUsed: 0, costUsd: 0.0),
            AgentDisplayState::new(name: 'b', status: 'working', operation: 'You are probing one in beta', elapsedSeconds: 0, tokensUsed: 0, costUsd: 0.0),
        ];

        $out = AgentLabelFolding::apply($states);

        $this->assertSame(
            ['You are … alpha', 'You are … beta'],
            [$out[0]->operation, $out[1]->operation],
        );
    }

    public function testTheDashboardSeamFoldsManagerEntries(): void
    {
        $manager = $this->twinRunManager();

        $entries = AgentDashboardPane::managerEntries($manager);

        $this->assertSame(
            ['You are … sugar-dash', 'You are … candy-mosaic'],
            array_map(static fn ($e): string => $e->operation, $entries),
        );
        $this->assertSame(
            ['run-a', 'run-b'],
            array_map(static fn ($e): string => $e->key, $entries),
            'row order and click keys are byte-untouched',
        );
    }

    public function testTheSplitColumnSeamFoldsRuns(): void
    {
        $manager = $this->twinRunManager();

        $out = AgentSplitColumn::renderRuns(array_values($manager->liveRuns()), Theme::default(), 40, 20);

        $this->assertStringContainsString('You are … sugar-dash', $out);
        $this->assertStringContainsString('You are … candy-mosaic', $out);
        $this->assertStringNotContainsString(
            'across many words',
            $out,
            'the shared middle never reaches the tile — it was folded away',
        );
    }

    private function twinRunManager(): AgentManager
    {
        $provider = $this->createMock(ProviderInterface::class);
        $manager = new AgentManager($provider, new SkillRegistry());
        $manager->register(new Agent(
            name: 'reviewer',
            description: 'Reviews code for bugs',
            prompt: 'You are a reviewer.',
            model: 'claude-sonnet-4-6',
            provider: 'anthropic',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
        ));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            'run-a',
            'reviewer',
            'You are auditing one library across many words in sugar-dash',
            1,
            '',
        ));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_PROGRESS,
            'run-a',
            'reviewer',
            '',
            2,
            '-> reading dash',
        ));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_STARTED,
            'run-b',
            'reviewer',
            'You are auditing one library across many words in candy-mosaic',
            1,
            '',
        ));
        $manager->projectRemoteSubAgent(new SubAgentActivity(
            SubAgentActivity::OP_PROGRESS,
            'run-b',
            'reviewer',
            '',
            2,
            '-> reading mosaic',
        ));

        return $manager;
    }
}

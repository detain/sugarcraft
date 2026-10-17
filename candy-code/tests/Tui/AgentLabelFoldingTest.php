<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Events\SubAgentActivity;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\AgentDisplayState;
use SugarCraft\Code\Tui\AgentLabelFolding;
use SugarCraft\Code\Tui\Components\AgentDashboardPane;
use SugarCraft\Code\Tui\Components\AgentSplitColumn;

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
            ['You are auditing sugar-core'],
            AgentLabelFolding::fold(['You are auditing sugar-core']),
        );
    }

    public function testASingleWordPrefixStaysByteIdentical(): void
    {
        // The shape pinned live: 'Audit sugar-core' / 'Audit sugar-ansi-parser'.
        $labels = ['Audit sugar-core', 'Audit sugar-ansi-parser', 'Audit sugar-constraints'];

        $this->assertSame($labels, AgentLabelFolding::fold($labels));
    }

    public function testTwoWordPrefixesActivateTheFold(): void
    {
        $folded = AgentLabelFolding::fold([
            'You are auditing one library in sugar-dashboard',
            'You are auditing one library in sugar-image',
        ]);

        $this->assertSame(
            ['You are … sugar-dashboard', 'You are … sugar-image'],
            $folded,
        );
    }

    public function testTheTailCarriesUpToTwoWordsFromTheDifference(): void
    {
        $folded = AgentLabelFolding::fold([
            'Repair the flaky suite in sugar-pty now please',
            'Repair the flaky suite in sugar-mosaic now please',
        ]);

        // Shared prefix is 5 words; the tail starts AT the difference.
        $this->assertSame(
            ['Repair the … sugar-pty now', 'Repair the … sugar-mosaic now'],
            $folded,
        );
    }

    public function testIdenticalLabelsNeverFold(): void
    {
        $labels = ['You are auditing sugar-dashboard', 'You are auditing sugar-dashboard'];

        $this->assertSame($labels, AgentLabelFolding::fold($labels));
    }

    public function testAPrefixOnlyMemberHoldsTheWholeGroupPlain(): void
    {
        // 'You are' has no remainder — the group cannot be disambiguated
        // honestly, so nothing folds.
        $labels = ['You are', 'You are auditing sugar-dashboard'];

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
            'You are auditing one very long shared prefix across many words in sugar-dashboard',
            'You are auditing one very long shared prefix across many words in sugar-image',
        ];
        $folded = AgentLabelFolding::fold($plain);

        foreach ([['sugar-dashboard', 0], ['sugar-image', 1]] as [$needle, $i]) {
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
            'You are auditing one library in sugar-dashboard',
            'You are auditing one library in sugar-image',
            'You are auditing one library in sugar-spring',
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
            'You are auditing one library in sugar-dashboard',
            'You are auditing one library in sugar-image',
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
            ['You are … sugar-dashboard', 'You are … sugar-image'],
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

        $this->assertStringContainsString('You are … sugar-dashboard', $out);
        $this->assertStringContainsString('You are … sugar-image', $out);
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
            'You are auditing one library across many words in sugar-dashboard',
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
            'You are auditing one library across many words in sugar-image',
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

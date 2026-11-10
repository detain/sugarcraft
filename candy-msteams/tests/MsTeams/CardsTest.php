<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Cards;
use PHPUnit\Framework\TestCase;

/**
 * §8.4 card-shape pins: the approval envelope Teams expects, the answer
 * vocabulary shared by both answer paths, and the retired replacement that
 * leaves NO action behind. Pure builders — no fake needed, no network, no
 * wall clock. The roster leg below is display-only by design; the answer
 * WALL lives in the router and is pinned in its own window.
 */
final class CardsTest extends TestCase
{
    public function testTheApprovalCardCarriesTheAskAndThreeSubmitActions(): void
    {
        $card = Cards::approvalCard(
            ['id' => 'ask_9', 'tool' => 'Bash', 'arguments' => ['command' => 'ls -la ' . str_repeat('x', 400)]],
            'Bash: ls -la …',
            ['boss', 'backup'],
        );

        self::assertSame('AdaptiveCard', $card['type']);
        self::assertSame('1.4', $card['version']);
        self::assertSame(Cards::CARD_SCHEMA, $card['$schema']);
        self::assertSame('Bash: ls -la …', $card['body'][1]['text'], 'the caller-prepared summary rides verbatim');
        self::assertStringContainsString('Approvers: boss, backup', $card['body'][2]['text']);

        self::assertCount(3, $card['actions']);
        $words = [];
        foreach ($card['actions'] as $action) {
            self::assertSame('Action.Submit', $action['type']);
            self::assertSame('ask_9', $action['data'][Cards::DATA_KEY]);
            $words[] = $action['data']['verdict'];
        }
        self::assertSame(['once', 'always', 'deny'], $words, 'the ask must offer exactly the §8.4 vocabulary');
    }

    public function testTheCardActivityWrapsTheAdaptiveContentType(): void
    {
        $activity = Cards::cardActivity(Cards::retiredCard('done'));

        self::assertSame('message', $activity['type']);
        self::assertSame('application/vnd.microsoft.card.adaptive', $activity['attachments'][0]['contentType']);
        self::assertSame('done', $activity['attachments'][0]['content']['body'][0]['text']);
    }

    public function testTheRetiredCardLeavesNoClickableAction(): void
    {
        $card = Cards::retiredCard('✅ Approved once by boss');

        self::assertSame('AdaptiveCard', $card['type']);
        self::assertArrayNotHasKey('actions', $card, 'a retired card must not collect a second answer');
        self::assertStringContainsString('Approved', $card['body'][0]['text']);
    }

    public function testTheTextFallbackParsesBothFormsAndMapsTheVerdict(): void
    {
        self::assertSame(
            ['askId' => 'ask_9', 'verdict' => 'once'],
            Cards::parseTextApprove('/approve ask_9'),
            'a bare /approve answers once — the conservative reading',
        );
        self::assertSame(
            ['askId' => 'ask_9', 'verdict' => 'reject'],
            Cards::parseTextApprove('  /APPROVE ask_9 DENY  '),
            'deny maps to the serve-side reject word',
        );
        self::assertSame(
            ['askId' => 'ask_9', 'verdict' => 'always'],
            Cards::parseTextApprove('/approve ask_9 always'),
        );
        self::assertNull(Cards::parseTextApprove('please approve this'));
        self::assertNull(Cards::parseTextApprove('/approve'), 'an approval line without an id is not an answer');
        self::assertNull(Cards::parseTextApprove('/approve ask_9 maybe'), 'unknown verdict words are not answers');
    }

    public function testTheSubmitValueCarriesTheSameVocabulary(): void
    {
        self::assertSame(
            ['askId' => 'ask_9', 'verdict' => 'always'],
            Cards::parseSubmitValue([Cards::DATA_KEY => 'ask_9', 'verdict' => 'ALWAYS']),
        );
        self::assertSame(
            ['askId' => 'ask_9', 'verdict' => 'once'],
            Cards::parseSubmitValue([Cards::DATA_KEY => 'ask_9', 'verdict' => 'wat']),
            'an unknown verdict word inside a REAL submit falls back to once',
        );
        self::assertNull(Cards::parseSubmitValue(['command' => 'anything-else']), 'ordinary value objects are not approvals');
        self::assertNull(Cards::parseSubmitValue([Cards::DATA_KEY => '']));
    }

    public function testTheVerdictMapIsTheSharedLawAcrossBothAnswerPaths(): void
    {
        self::assertSame(['once' => 'once', 'always' => 'always', 'deny' => 'reject'], Cards::VERDICTS);
    }
}

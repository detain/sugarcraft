<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Attachment;
use SugarCraft\Crush\AttachmentType;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\ToolResult;

/**
 * Lane B (skills-qa F1/F2): the transcript says WHICH skill ran and WHICH
 * skill a `$name` mention attached. A Skill row heads as `✨ skill: <name>` —
 * the name rides the finished result's arguments on both settle paths — and a
 * skill attachment's chip reads `📎 skill: <name>` instead of the SKILL.md
 * basename every skill shares. Ordinary rows and chips are byte-unchanged.
 */
final class SkillToolRowTest extends TestCase
{
    protected function setUp(): void
    {
        Renderer::clearZones();
    }

    public function testASkillRowNamesTheSkillItLoaded(): void
    {
        $plain = self::plain($this->frame([self::skillRow('review')]));

        $this->assertStringContainsString('✨ skill: review', $plain);
        $this->assertStringNotContainsString('🔧 tool: Skill', $plain, 'the generic head was not replaced');
    }

    public function testAnOrdinaryToolRowHeadIsUnchanged(): void
    {
        $plain = self::plain($this->frame([
            Message::assistant('')->withToolResults([new ToolResult('Bash', 'done', id: 'c_b')]),
        ]));

        $this->assertStringContainsString('🔧 tool: Bash', $plain);
    }

    public function testASkillRowWithoutAUsableNameFallsBackToTheToolHead(): void
    {
        $noArgs = Message::assistant('')->withToolResults([new ToolResult('Skill', 'body', id: 'c1')]);
        $plain = self::plain($this->frame([$noArgs]));

        $this->assertStringContainsString('🔧 tool: Skill', $plain, 'an argument-less Skill row keeps the old head');
        $this->assertStringNotContainsString('✨ skill:', $plain);
    }

    /**
     * The head swap must not break the two laws every other tool row obeys: the
     * row stays a single-row click zone keyed by the call id, and it stays
     * truncated-to-fitting even when the pane is too narrow for the whole head
     * (below TOOL_DESCRIPTION_MIN_COLS the suffix is gone and the head alone is
     * what must still fit and survive the cut).
     */
    public function testTheSkillRowStaysClickableAndFitsTheNarrowestPane(): void
    {
        foreach ([100, 40, 22] as $cols) {
            Renderer::clearZones();
            $frame = $this->frame([self::skillRow('security-audit-and-deploy')], $cols);

            $zone = Renderer::scanner()->get(Renderer::TOOL_CALL_ZONE_PREFIX . 'c_skill');
            $this->assertNotNull($zone, "the skill row lost its click zone at {$cols} columns");
            $this->assertSame($zone->startRow, $zone->endRow, "the skill zone spans rows at {$cols} columns");

            $rows = array_values(array_filter(
                explode("\n", self::plain($frame)),
                static fn (string $row): bool => str_contains($row, 'skill:'),
            ));
            $this->assertCount(1, $rows, "the skill row wrapped into a lookalike at {$cols} columns");
            $this->assertLessThanOrEqual($cols, Width::of(trim($rows[0], '│ ')));
        }

        // Even at the narrowest width the head keeps at least one cell of the
        // name - the thing that makes this row say more than the generic head.
        $plain = self::plain($this->frame([self::skillRow('review')], 22));
        $this->assertMatchesRegularExpression('/✨ skill: \S/', $plain);
    }

    public function testASkillMentionChipNamesTheSkill(): void
    {
        $prompt = Message::user('please $review this')
            ->attachFile('/home/dev/.claude/skills/review/SKILL.md', 'Check every line.', 'review');
        $chat = new Chat(history: [$prompt], rows: 30, cols: 100);

        $plain = self::plain($chat->view());

        $this->assertStringContainsString('📎 skill: review', $plain);
        $this->assertStringNotContainsString('📎 SKILL.md', $plain, 'the old basename chip still renders');
    }

    public function testTheSkillLabelSurvivesTheSnapshotRoundTrip(): void
    {
        $original = Message::user('$review')->attachFile('/s/review/SKILL.md', 'body', 'review');
        $row = $original->jsonSerialize();

        $this->assertSame('review', $row['attachments'][0]['skill']);

        $revived = Message::fromArray(json_decode(json_encode($row, JSON_THROW_ON_ERROR), true));
        $this->assertSame('review', $revived->attachments[0]->skill);
    }

    public function testALegacySnapshotWithoutTheSkillKeyStillLoads(): void
    {
        $revived = Message::fromArray([
            'role' => 'user',
            'content' => '$review',
            'attachments' => [['path' => '/s/review/SKILL.md', 'type' => 'File', 'dataBase64' => base64_encode('body')]],
        ]);

        $this->assertNull($revived->attachments[0]->skill, 'rows persisted before lane B load as plain files');
        $this->assertEquals(new Attachment('/s/review/SKILL.md', AttachmentType::File, 'body'), $revived->attachments[0]);
    }

    public function testAPlainAttachmentEmitsNoSkillKey(): void
    {
        $row = Message::user('x')->attachFile('/tmp/a.txt', 'body')->jsonSerialize();

        $this->assertArrayNotHasKey('skill', $row['attachments'][0], 'the encoder stays byte-stable for every other row');
    }

    private function skillRow(string $name): Message
    {
        return Message::assistant('')->withToolResults([new ToolResult(
            'Skill',
            'the skill body',
            id: 'c_skill',
            arguments: ['name' => $name],
        )]);
    }

    /** @param list<Message> $history */
    private function frame(array $history, int $cols = 100): string
    {
        return Renderer::render(new Chat(history: $history, rows: 40, cols: $cols));
    }

    private static function plain(string $frame): string
    {
        $stripped = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $frame);

        // The group keeps the `(` veto that holds this literal out of the
        // GlobDialect corpus harvest (a bare `*` without a veto character
        // would drift the PathGlob doc-block pair figure).
        return (string) preg_replace("/\x{e000}([^\x{e001}]*)\x{e001}/u", '', $stripped);
    }
}

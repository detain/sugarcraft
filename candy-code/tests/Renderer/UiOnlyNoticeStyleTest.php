<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;

/**
 * Audit 15b-03-rem(b), decision N1: mid-turn notices (queued, refused,
 * runtime, background) stay INLINE where they happened - even between a
 * prompt and its answer - and render in a distinct dim style, so the user can
 * tell them from the system rows the model does see.
 *
 * Before the fix a UI-only notice and an agent-visible system row both
 * rendered as `system: …` in the same colour.
 */
final class UiOnlyNoticeStyleTest extends TestCase
{
    private const ITALIC = "\e[3m";

    /** @return list<string> the frame's rows */
    private static function rows(Chat $chat): array
    {
        return explode("\n", Renderer::render($chat));
    }

    private static function withoutSgr(string $row): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $row);
    }

    /** @param list<string> $rows */
    private static function rowContaining(array $rows, string $needle): ?string
    {
        foreach ($rows as $row) {
            if (str_contains(self::withoutSgr($row), $needle)) {
                return $row;
            }
        }

        return null;
    }

    public function testAUiOnlyNoticeRendersWithItsOwnLabelInItalicDim(): void
    {
        $rows = self::rows(new Chat(history: [
            Message::user('first question'),
            Message::notice('Queued (1 waiting)'),
            Message::system('hook context for the model'),
            Message::assistant('first answer'),
        ]));

        $notice = self::rowContaining($rows, 'Queued (1 waiting)');
        $this->assertNotNull($notice);
        $this->assertStringContainsString('notice: Queued (1 waiting)', self::withoutSgr($notice));
        $this->assertStringContainsString(self::ITALIC, $notice, 'the notice is italic');

        $system = self::rowContaining($rows, 'hook context for the model');
        $this->assertNotNull($system);
        $this->assertStringContainsString('system: hook context for the model', self::withoutSgr($system), 'an agent-visible system row keeps its label');
        $this->assertStringNotContainsString(self::ITALIC, $system, 'and is not italic: the two must look different');
    }

    public function testTheNoticeKeepsTheDimColourOfASystemRow(): void
    {
        $rows = self::rows(new Chat(history: [Message::notice('N-ROW'), Message::system('S-ROW')]));

        preg_match('/\e\[38;[0-9;]+m(?=(?:notice|system): )/', (string) self::rowContaining($rows, 'N-ROW'), $noticeColour);
        preg_match('/\e\[38;[0-9;]+m(?=(?:notice|system): )/', (string) self::rowContaining($rows, 'S-ROW'), $systemColour);

        $this->assertNotSame([], $noticeColour, 'the notice carries a foreground colour');
        $this->assertSame($systemColour, $noticeColour, 'the same dim colour - distinct by italics and label, not by brightness');
    }

    public function testNoticesStayInlineBetweenAPromptAndItsAnswer(): void
    {
        $plain = array_map(self::withoutSgr(...), self::rows(new Chat(history: [
            Message::user('PROMPT-ONE'),
            Message::notice('/budget is a command, and commands do not run while a turn is in flight'),
            Message::notice('Queued (1 waiting)'),
            Message::assistant('ANSWER-ONE'),
        ])));

        $at = static function (string $needle) use ($plain): int {
            foreach ($plain as $index => $row) {
                if (str_contains($row, $needle)) {
                    return $index;
                }
            }

            return -1;
        };

        $this->assertGreaterThan($at('PROMPT-ONE'), $at('notice: /budget'));
        $this->assertGreaterThan($at('notice: /budget'), $at('notice: Queued'));
        $this->assertGreaterThan($at('notice: Queued'), $at('ANSWER-ONE'), 'not reordered after the answer');
    }

    /**
     * ISS-097: a UI-only ASSISTANT row - the reply a slash command writes into
     * the history, a background echo - is the app talking, not the model. It
     * used to wear the same bold `assistant` label as model output.
     */
    public function testALocalCommandReplyWearsTheLocalLabelNotAssistant(): void
    {
        $plain = implode("\n", array_map(self::withoutSgr(...), self::rows(new Chat(history: [
            Message::user('/skills'),
            Message::assistant('SKILLS-LISTING')->withUiOnly(),
            Message::assistant('MODEL-REPLY'),
        ]))));

        $this->assertStringContainsString("local\nSKILLS-LISTING", $plain);
        $this->assertStringNotContainsString("assistant\nSKILLS-LISTING", $plain);
        $this->assertStringContainsString("assistant\nMODEL-REPLY", $plain, 'the model reply keeps its own label');
    }

    /**
     * ISS-097: an error-flagged row opens with an error-coloured `error`
     * label and closes with the recall hint - never under `assistant`, and
     * never by sniffing the message text (an unflagged row that happens to
     * contain the word error is a plain local/assistant row).
     */
    public function testAnErrorRowWearsTheErrorLabelAndTheRetryHint(): void
    {
        $rows = self::rows(new Chat(history: [
            Message::user('go'),
            Message::assistant('_[error: SGLANG request failed: Connection refused]_')->withUiOnly()->withError(),
            Message::assistant('an answer that merely mentions an error'),
        ]));

        $plain = implode("\n", array_map(self::withoutSgr(...), $rows));

        $this->assertStringContainsString("error\n[error: SGLANG request failed: Connection refused]", $plain);
        $this->assertStringContainsString('retry: ↑ then Enter', $plain);
        $this->assertStringNotContainsString("assistant\n[error:", $plain, 'a provider error is not painted as the assistant speaking');

        $errorRow = self::rowContaining($rows, 'error');
        $hintRow = self::rowContaining($rows, 'retry: ↑');
        $this->assertNotSame([], (array) preg_match('/\e\[38;[0-9;]+m/', (string) $errorRow), 'the label carries a colour');
        $this->assertNotSame([], (array) preg_match('/\e\[38;[0-9;]+m/', (string) $hintRow), 'the hint carries a colour');

        $unflagged = self::rowContaining($rows, 'an answer that merely mentions');
        $this->assertNotNull($unflagged);
        $block = implode("\n", array_map(self::withoutSgr(...), array_slice($rows, 0, (int) array_search($unflagged, $rows, true) + 1)));
        $this->assertStringContainsString("assistant\nan answer that merely mentions an error", $block, 'text alone never earns the error paint');
    }

    /**
     * ISS-097's producer half for a refused provider switch: the sentence is
     * built at {@see Chat}'s `/model` failure arm, so THAT is where the flag
     * has to be set — a row that reaches the renderer unflagged paints as the
     * assistant talking. Driven through the command, not hand-built, so the
     * producer cannot regress without reddening this.
     */
    public function testARefusedProviderSwitchReachesTheRenderPathFlagged(): void
    {
        [$next] = (new Chat(inputBuf: '/model nosuchprovider'))
            ->update(new KeyMsg(KeyType::Enter, ''));

        $row = $next->history[count($next->history) - 1];
        $this->assertTrue($row->error, 'the switch failure flags its own row');
        $this->assertTrue($row->uiOnly, 'and stays out of the model view');

        $rows = self::rows($next);
        $plain = implode("\n", array_map(self::withoutSgr(...), $rows));
        $this->assertStringContainsString("error\nCould not switch to provider", $plain);
        $this->assertStringContainsString('retry: ↑ then Enter', $plain, 'the error row closes with the recall hint');
        $this->assertStringNotContainsString("assistant\nCould not switch", $plain);
    }

    /**
     * The error label must not be the assistant label recoloured by accident:
     * the two foreground sequences differ on the same frame.
     */
    public function testTheErrorLabelColourDiffersFromTheAssistantLabel(): void
    {
        $rows = self::rows(new Chat(history: [
            Message::assistant('MODEL-REPLY'),
            Message::assistant('_[error: boom]_')->withUiOnly()->withError(),
        ]));

        $colourOf = static function (string $label) use ($rows): array {
            foreach ($rows as $row) {
                if (self::withoutSgr($row) === $label && preg_match('/\e\[38;[0-9;]+m/', $row, $m) === 1) {
                    return $m;
                }
            }

            return [];
        };

        $assistant = $colourOf('assistant');
        $error = $colourOf('error');

        $this->assertNotSame([], $assistant);
        $this->assertNotSame([], $error);
        $this->assertNotSame($assistant, $error, 'errors read as errors at a glance');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\CommandSpec;
use SugarCraft\Code\Message;
use SugarCraft\Code\Palette\PaletteAction;
use SugarCraft\Code\Palette\PaletteState;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;

/**
 * C-EXT-5.b: the PREFILL half of the now-full-superset palette.
 *
 * Rows whose spec carries no PaletteAction still reach the root list — as
 * `/<name>` rows whose Enter closes the palette and fills a BLANK draft with
 * the bare command plus a trailing space, caret at the end. A non-blank draft
 * is KEPT and the row answers with one notice line (the draft-preservation
 * law, the same law {@see \SugarCraft\Code\Chat::refuseInFlightCommand()}
 * enforces for typed mid-turn commands); mid-turn the blank-draft fill stands
 * because a draft write touches no turn state.
 *
 * The interactive surface's own proof (the same answer through arrow keys,
 * Enter and clicks) rides the pre-existing walk in
 * {@see \SugarCraft\Code\Tests\PaletteClickTest::testEveryPaletteRowAnswersAClickExactlyAsItAnswersEnter()};
 * this file pins the contract the walk cannot: what the draft, the transcript
 * and the MRU end up as.
 */
final class PalettePrefillRowsTest extends TestCase
{
    private function prefillFixture(string $draft = '', bool $inFlight = false, array $custom = []): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
            customCommands: $custom,
            palette: PaletteState::root(),
            inFlight: $inFlight,
        ))->withSize(100, 30);
    }

    /**
     * The row the palette shows for a command — the bare '/name' or the
     * hint-carrying '/name <hint>' — or null when the list does not carry it.
     */
    private function carriedLabel(Chat $chat, string $name): ?string
    {
        foreach ($chat->paletteMatches() as $label) {
            if ($label === '/' . $name || str_starts_with($label, '/' . $name . ' ')) {
                return $label;
            }
        }

        return null;
    }

    public function testEverySurvivorRowCarriesAPrefillRowThatFillsABlankDraft(): void
    {
        $walked = 0;
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->paletteAction !== null || !$spec->slashVisible || $spec->isFileBased()) {
                continue;
            }
            ++$walked;

            $chat = $this->prefillFixture();
            $label = $this->carriedLabel($chat, $spec->name);
            self::assertNotNull($label, "/{$spec->name} has no prefill row in the root palette");

            [$next, $cmd] = $chat->runPaletteAction($label);

            self::assertNull($cmd, "the prefill row for /{$spec->name} dispatched a command");
            self::assertSame('/' . $spec->name . ' ', $next->inputBuf, "the prefill for /{$spec->name} mis-wrote the draft");
            self::assertSame(
                strlen('/' . $spec->name . ' '),
                $next->inputCursorOffset(),
                'the caret must sit at the end of the prefilled command, ready to type arguments',
            );
            self::assertNull($next->palette(), 'running a prefill row closes the palette');
            self::assertCount(2, $next->history, 'a blank-draft prefill writes no transcript row');
            self::assertSame($label, $next->paletteMru()[0], 'running a prefill row records it in the MRU');
        }

        self::assertGreaterThanOrEqual(15, $walked, 'fixture: the survivor set must be a real walk, not a vacuous loop');
    }

    public function testAFileCommandAndAnMcpPromptBothArriveAsPrefillRows(): void
    {
        $fileShadow = CommandSpec::new(
            name: 'undo',
            description: 'the operator reclaimed /undo',
            category: 'Custom',
            template: 'shadowed /undo ran',
        );
        $mcpPrompt = CommandSpec::new(
            name: 'mcp__srv__prompt',
            description: 'a prompt from the mcp server, as a command',
            category: 'Custom',
            template: 'mcp prompt ran',
        );

        $chat = $this->prefillFixture(custom: ['undo' => $fileShadow, 'mcp__srv__prompt' => $mcpPrompt]);

        // The file shadow REPLACES the built-in /undo row (no PaletteAction
        // of its own), so it must reach the list as a prefill row — while the
        // built-in's action row stays next to it and keeps its old dispatch.
        self::assertSame('/undo', $this->carriedLabel($chat, 'undo'));
        self::assertContains(PaletteAction::UndoTurn->label(), $chat->paletteMatches());
        self::assertSame('/mcp__srv__prompt', $this->carriedLabel($chat, 'mcp__srv__prompt'));

        [$next] = $chat->runPaletteAction('/mcp__srv__prompt');
        self::assertSame('/mcp__srv__prompt ', $next->inputBuf, 'running the prompt row fills the draft, never runs the template');
        self::assertCount(2, $next->history);
    }

    public function testAPrefillRowKeepsANonBlankDraftAndSaysSoInOneLine(): void
    {
        $chat = $this->prefillFixture(draft: 'long running task');

        [$next, $cmd] = $chat->runPaletteAction('/status');

        self::assertNull($cmd);
        self::assertSame('long running task', $next->inputBuf, 'the draft-preservation law: a filled box is never overwritten');
        self::assertNull($next->palette(), 'the prefill row still closes the palette when it keeps the draft');
        self::assertCount(3, $next->history, 'the kept draft is announced in exactly one row');
        $notice = $next->history[2];
        self::assertSame('system', $notice->role->value);
        self::assertTrue($notice->uiOnly, 'the notice is for the user, never the model wire');
        self::assertStringContainsString('/status', $notice->content);
        self::assertStringNotContainsString("\n", $notice->content, 'one line, not a paragraph');
    }

    public function testAPrefillRowIsAllowedMidTurnWhileActionRowsKeepRefusing(): void
    {
        $chat = $this->prefillFixture(inFlight: true);
        $label = $this->carriedLabel($chat, 'fork');
        self::assertNotNull($label, 'fixture: /fork carries a prefill row');

        [$next, $cmd] = $chat->runPaletteAction($label);

        self::assertNull($cmd);
        self::assertTrue($next->inFlight, 'the prefill itself must not touch turn state');
        self::assertSame('/fork ', $next->inputBuf, 'mid-turn, the blank draft is still filled — prefilling is not submitting');
        self::assertCount(2, $next->history, 'a mid-turn prefill is allowed, so it writes no refusal row');

        // The contrast arm: an ACTION row mid-turn still refuses exactly as
        // before — C-EXT-5.b widened the palette's list, not the overlay rule
        // for dispatches that write turn state.
        $busy = $this->prefillFixture(inFlight: true);
        [$refused] = $busy->runPaletteAction(PaletteAction::ShareSession->label());
        self::assertCount(3, $refused->history, 'fixture: the action-row refusal still appends its row');
        self::assertSame('', $refused->inputBuf, 'the refusal half leaves the draft alone');
    }

    public function testTheInteractiveEnterRouteFillsTheDraftThroughRunRootPaletteAction(): void
    {
        $chat = new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            backend: new EchoBackend(),
            palette: PaletteState::root(),
        );
        $index = array_search('/status', $chat->paletteMatches(), true);
        self::assertIsInt($index, 'fixture: /status carries a prefill row');

        $current = $chat;
        for ($step = 0; $step < $index; $step++) {
            [$current] = $current->update(new KeyMsg(KeyType::Down, ''));
        }
        self::assertSame($index, $current->palette()->selectedIndex);

        [$next, $cmd] = $current->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNull($cmd, 'Enter on a prefill row dispatches no command');
        self::assertSame('/status ', $next->inputBuf);
        self::assertNull($next->palette(), 'Enter closed the palette');
        self::assertCount(2, $next->history, 'the blank-draft fill writes no transcript row');
    }

    public function testTheActionHalfStillLeadsTheListAndThePrefillsTrailIt(): void
    {
        $labels = $this->prefillFixture()->paletteMatches();

        self::assertGreaterThan(30, count($labels), 'the superset list is the real registry surface');
        self::assertStringStartsNotWith('/', $labels[0], 'action rows are prose sentences and stay ahead of the prefill bucket');
        self::assertStringStartsWith(
            '/',
            $labels[count($labels) - 1],
            'prefill rows bucket under the actions (no category header of their own) and trail them',
        );
        self::assertContains('New session', $labels, 'the historic head row is still in the list');
    }
}

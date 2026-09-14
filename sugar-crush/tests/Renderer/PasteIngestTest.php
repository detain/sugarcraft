<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Role;

/**
 * E704: bracketed paste end-to-end.
 *
 * The two enablement halves and every semantic the operator ruled on, pinned
 * at the level each one lives on: the `bracketedPaste` flag on the options the
 * binary launches with (without it the terminal never wraps a paste in the
 * `CSI 200~ … CSI 201~` envelope and every pasted line submits on its own);
 * the envelope's wire shape at `InputReader` (ONE paste message, newlines kept
 * inside it, escapes already stripped by the default sanitize); and the ingest
 * at `Chat::update()` — insert-at-caret, cursor at the end of the pasted text,
 * verbatim content, no submit, no pre-trim, and the user's own Enter after
 * that submitting the WHOLE draft as one message.
 *
 * The pre-E704 behaviour was a deliberate drop, pinned by identity in
 * {@see KeyHelpTest::testTheTwoRoutesAgreeOnEveryBlankAndNonBlankDraft()};
 * that pin is flipped there, and this file is what the flip points at.
 */
final class PasteIngestTest extends TestCase
{
    private function pasteBox(string $draft = '', int $cols = 100, int $rows = 30): Chat
    {
        return (new Chat(
            history: [],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize($cols, $rows);
    }

    /**
     * The frame with every SGR/OSC sequence stripped — what a row of the
     * input box LOOKS like once the cursor styling and borders' colours are
     * taken away, which is all a "does the draft paint on two rows" question
     * needs. (Named apart from InputWrapTest::plain(): the helper-drift guard
     * groups private helpers BY NAME across files.)
     */
    private function plainFrameOf(Chat $chat): string
    {
        $view = $chat->view();

        return (string) preg_replace(
            '/\e\[[0-9;?]*[a-zA-Z]|\e\][^\a\e]*(\a|\e\\\\)/',
            '',
            is_string($view) ? $view : $view->body,
        );
    }

    // ── enablement half one: the flag ────────────────────────────────────

    public function testTheProgramLaunchesWithBracketedPasteAskedFor(): void
    {
        $options = Chat::programOptions();

        $this->assertTrue(
            $options->bracketedPaste,
            'E704: without mode 2004 the terminal sends the paste as a byte burst and each Enter in it '
            . 'submits a line on its own — the whole bug this pins the door against',
        );
        $this->assertFalse(
            (new ProgramOptions())->bracketedPaste,
            'fixture: candy-core defaults this OFF, so the line above is Chat opting IN, not inheriting',
        );
        $this->assertTrue($options->useAltScreen, 'fixture: the alt-screen the app has always asked for');
        $this->assertTrue(
            $options->sanitizePaste,
            'the default sanitize stays ON: paste payloads are escape/control-stripped at InputReader '
            . 'before Chat ever sees them — the ingest arm must not widen that',
        );
    }

    // ── enablement half two: the wire ────────────────────────────────────

    public function testOneBracketedEnvelopeDecodesToOnePasteMessage(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~line1\nline2\x1b[201~");

        $pastes = array_values(array_filter(
            $msgs,
            static fn (object $msg): bool => $msg instanceof PasteMsg,
        ));
        $this->assertCount(1, $pastes, 'the envelope is ONE message, not a message per line');
        $this->assertSame("line1\nline2", $pastes[0]->content, 'newlines survive inside the envelope');
        $this->assertSame(
            [],
            array_values(array_filter(
                $msgs,
                static fn (object $msg): bool => $msg instanceof KeyMsg,
            )),
            'and none of the envelope bytes, the newline included, decodes as a keystroke — which is '
            . 'exactly the per-line-submit burst the flag exists to prevent',
        );
        $this->assertInstanceOf(PasteStartMsg::class, $msgs[0], 'fixture: envelope shape on the wire');
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[1]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[2]);
    }

    public function testTheSanitizeIsTheBoundaryTheArmInsertsWhatItIsGiven(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~hi\x1b[7mred\x07!\x1b[201~");
        $paste = $msgs[2] ?? null;

        $this->assertInstanceOf(PasteMsg::class, $paste);
        $this->assertSame(
            'hired!',
            $paste->content,
            'the default-on sanitize stripped the escape and the BEL upstream — printable text survives',
        );

        [$after] = $this->pasteBox()->update($paste);
        $this->assertSame(
            'hired!',
            $after->inputBuf,
            'and update() inserts the payload VERBATIM — the arm adds no second strip and, symmetrically, '
            . 'no second licence: whatever the boundary decided is exactly what the draft gets',
        );
    }

    // ── the ingest arm ───────────────────────────────────────────────────

    public function testPasteIntoAnEmptyDraftComposesOneMultiRowDraftWithTheCaretAtTheEnd(): void
    {
        [$after] = $this->pasteBox()->update(new PasteMsg("line1\nline2"));

        $this->assertSame("line1\nline2", $after->inputBuf);
        $this->assertSame(11, $after->inputCursorOffset(), 'caret at the END of the pasted text');
        $this->assertSame(1, $after->input->line(), '…which for a two-row paste is row 1');
        $this->assertSame(5, $after->input->column(), '…at column 5 — the row-reset law every single edit follows');
        $this->assertSame([], $after->history, 'a paste alone sends nothing');
        $this->assertFalse($after->inFlight);
    }

    public function testThePastedDraftRendersAsGenuineTwoRowBoxContent(): void
    {
        [$after] = $this->pasteBox()->update(new PasteMsg("line1\nline2"));

        $rows = explode("\n", $this->plainFrameOf($after));

        $withLine1 = array_values(array_filter($rows, static fn (string $r): bool => str_contains($r, 'line1')));
        $withLine2 = array_values(array_filter($rows, static fn (string $r): bool => str_contains($r, 'line2')));
        $this->assertCount(1, $withLine1, 'the first pasted row paints on exactly one frame row');
        $this->assertCount(1, $withLine2, 'the second pasted row paints on its OWN frame row — the box grew');
        $this->assertNotSame($withLine1, $withLine2, 'and the two rows are distinct frame rows');
    }

    public function testTheEnterAfterAPasteSubmitsTheWholeDraftAsOneMessage(): void
    {
        $chat = $this->pasteBox();
        [$pasted] = $chat->update(new PasteMsg("line1\nline2"));
        [$submitted] = $pasted->update(new KeyMsg(KeyType::Enter));

        $users = array_values(array_filter(
            $submitted->history,
            static fn ($m): bool => $m->role === Role::User,
        ));
        $this->assertCount(1, $users, 'ONE history message for the whole two-line paste');
        $this->assertSame("line1\nline2", $users[0]->content);
        $this->assertSame('', $submitted->inputBuf, 'and the box emptied, as every submit does');
    }

    public function testAPasteWithATrailingEnterIsStillOneDraftAndOneSubmitTrimmedAsAlways(): void
    {
        $chat = $this->pasteBox();
        [$pasted] = $chat->update(new PasteMsg("line1\nline2\n"));

        $this->assertSame(
            "line1\nline2\n",
            $pasted->inputBuf,
            'verbatim: the trailing newline is the DRAFT\'s until submit() speaks — no pre-trim here',
        );

        [$submitted] = $pasted->update(new KeyMsg(KeyType::Enter));
        $users = array_values(array_filter(
            $submitted->history,
            static fn ($m): bool => $m->role === Role::User,
        ));
        $this->assertCount(1, $users, 'the paste submits ONCE, not once per line');
        $this->assertSame(
            "line1\nline2",
            $users[0]->content,
            'and submit()\'s existing trim eats the trailing newline — unchanged on the submit side',
        );
    }

    public function testAnEmptyPasteIsANoOp(): void
    {
        $chat = $this->pasteBox('why');
        [$after] = $chat->update(new PasteMsg(''));

        $this->assertSame('why', $after->inputBuf);
        $this->assertSame(3, $after->inputCursorOffset(), 'the caret did not move');
        $this->assertSame([], $after->history);
    }

    public function testAPasteIntoMidDraftInsertsAtTheCaretNotAtTheEnd(): void
    {
        $chat = $this->pasteBox('ab');
        [$moved] = $chat->update(new KeyMsg(KeyType::Left));
        [$after] = $moved->update(new PasteMsg('X'));

        $this->assertSame('aXb', $after->inputBuf);
        $this->assertSame(2, $after->inputCursorOffset(), 'caret sits just past the inserted text');
    }

    public function testAMultiRowPasteIntoAMultiRowDraftSplitsUnderTheCaret(): void
    {
        $chat = $this->pasteBox("ab\ncd");
        [$moved] = $chat->update(new KeyMsg(KeyType::Left));
        $this->assertSame(4, $moved->inputCursorOffset(), 'fixture: caret between c and d');

        [$after] = $moved->update(new PasteMsg("X\nY"));

        $this->assertSame("ab\ncX\nYd", $after->inputBuf);
        $this->assertSame(7, $after->inputCursorOffset(), 'end of the pasted text, not of the row it started in');
    }

    // ── the whole chain, bytes to history ────────────────────────────────

    public function testTheFullChainFromEnvelopeBytesToOneUserMessage(): void
    {
        $chat = $this->pasteBox();
        foreach ((new InputReader())->parse("\x1b[200~a\nb\x1b[201~") as $msg) {
            [$chat] = $chat->update($msg);
        }

        $this->assertSame("a\nb", $chat->inputBuf, 'Start/End markers dropped, payload ingested');

        [$submitted] = $chat->update(new KeyMsg(KeyType::Enter));
        $users = array_values(array_filter(
            $submitted->history,
            static fn ($m): bool => $m->role === Role::User,
        ));
        $this->assertCount(1, $users, 'the two-line paste reached history as ONE message');
        $this->assertSame("a\nb", $users[0]->content);
    }

    public function testTheIngestIsRepeatableWithNoStateBetweenRuns(): void
    {
        $once = $this->pasteBox();
        [$once] = $once->update(new PasteMsg("line1\nline2"));
        [$once] = $once->update(new KeyMsg(KeyType::Enter));

        $twice = $this->pasteBox();
        [$twice] = $twice->update(new PasteMsg("line1\nline2"));
        [$twice] = $twice->update(new KeyMsg(KeyType::Enter));

        $this->assertSame($once->inputBuf, $twice->inputBuf);
        $this->assertSame(
            array_map(static fn ($m): string => $m->role->name . '|' . $m->content, $once->history),
            array_map(static fn ($m): string => $m->role->name . '|' . $m->content, $twice->history),
        );
    }
}

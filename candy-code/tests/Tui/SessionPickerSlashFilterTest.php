<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\SessionListAction;
use SugarCraft\Code\Tui\SessionPicker;

/**
 * rv-qa-2 (MAJOR): Enter while the `/` filter holds a slash command the user
 * meant for the input line ("/exit") used to RESUME the highlighted row — the
 * opposite of leaving. The overlay now refuses loudly instead: it stays open,
 * nothing is resumed, and the notice names the way out. Escape's own exit
 * path (clear filter, then close) is pinned here too, along with the untouched
 * behaviors: Enter on a plain filter text still resumes, and a slash that is
 * not the first character reads as text, not as a command.
 *
 * @internal
 */
final class SessionPickerSlashFilterTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['sessionId' => 'ea907059', 'sessionName' => 'Auth refactor', 'title' => 'Auth refactor', 'summary' => 'first', 'gitBranch' => 'main', 'lastActivity' => ''],
            ['sessionId' => 'b2', 'sessionName' => 'Fix flaky PTY test', 'title' => 'Fix flaky PTY test', 'summary' => 'second', 'gitBranch' => 'feature/pty', 'lastActivity' => ''],
        ];
    }

    /** @param list<string> $keys */
    private function press(SessionPicker $picker, array $keys): SessionPicker
    {
        foreach ($keys as $key) {
            [$picker] = $picker->handleKey($key);
        }

        return $picker;
    }

    public function testEnterOnASlashCommandFilterRefusesInsteadOfResuming(): void
    {
        [$picker, $action, $cmd] = $this->press(SessionPicker::new($this->rows()), ['/', 'e', 'x', 'i', 't'])->handleKey('enter');

        $this->assertNotSame('resume', $action, 'the repro: "/exit" + Enter must never resume a session');
        $this->assertNull($cmd, 'no side-effect closure rides the refusal arm the host could fire');
        $this->assertSame('edit', $action, 'the overlay stays hosted-open on a refusal');
        $this->assertTrue($picker->isFiltering());
        $this->assertSame('exit', $picker->query(), 'the "/" opened the filter and "exit" typed into it — the repro shape');
        $this->assertNotNull($picker->notice());
        $this->assertStringContainsString('slash command', (string) $picker->notice());
        $this->assertStringContainsString('Escape', (string) $picker->notice(), 'the refusal names the way out');
    }

    public function testTheRefusalSurvivesFurtherKeystrokesOnlyUntilTheNextKey(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'e', 'x', 'i', 't']);
        [$refused] = $picker->handleKey('enter');
        $this->assertNotNull($refused->notice());

        [$typedOn, $action] = $refused->handleKey(' ');
        $this->assertNull($typedOn->notice(), 'any keystroke retires the last refusal');
        // C-SES-2: a typed character is a QUERY EDIT, and every query edit
        // reports Filter so the host re-reads the FTS page behind its
        // debounce — 'edit' here would strand body hits behind the stale
        // page the refusal left up.
        $this->assertSame(SessionListAction::Filter, $action);

        [, $erased] = $typedOn->handleKey('backspace');
        $this->assertSame(SessionListAction::Filter, $erased, 'backspace that leaves text is an edit too');
    }

    public function testEscapePathClearsThenClosesWithoutEverResuming(): void
    {
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'e', 'x', 'i', 't']);

        [$cleared, $first] = $picker->handleKey('escape');
        $this->assertSame('edit', $first);
        $this->assertFalse($cleared->isFiltering());
        $this->assertSame('', $cleared->query());

        [, $second] = $cleared->handleKey('escape');
        $this->assertSame('close', $second, 'the second Escape closes the overlay — the exit the user typed "/exit" for');
        $this->assertNotSame('resume', $second);
    }

    public function testEnterOnAPlainFilterTextStillResumesTheMatch(): void
    {
        [$picker, $action] = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't', 'y'])->handleKey('enter');

        $this->assertSame('resume', $action, 'the filter keeps its primary job: jump to the best match');
    }

    public function testWordsThatAreNotCommandsStayOrdinaryFilters(): void
    {
        // A slash mid-query and near-miss words are legitimate filter text;
        // only an exact (case-insensitive) command word is read as a mis-typed
        // command. 'exits' fuzzy-filters; 'exit' is the /exit command.
        [$picker, $action] = $this->press(SessionPicker::new($this->rows()), ['/', 'p', 't', '/', 'p'])->handleKey('enter');
        $this->assertSame('resume', $action, '"pt/p" is a filter, Enter resumes its best match');

        [$nearPicker, $nearAction] = $this->press(SessionPicker::new($this->rows()), ['/', 'e', 'x', 'i', 't', 's'])->handleKey('enter');
        $this->assertNull($nearPicker->notice(), '"exits" is not a registered command — no refusal, ordinary Enter');
        $this->assertNotSame('edit', $nearAction, 'the command-refusal arm did not fire on a near-miss word');

        [, $caseAction] = $this->press(SessionPicker::new($this->rows()), ['/', 'E', 'X', 'I', 'T'])->handleKey('enter');
        $this->assertNotSame('resume', $caseAction, 'command matching is case-insensitive');
    }

    public function testALiteralLeadingSlashIsRefusedEvenForNonCommands(): void
    {
        // Backspacing to an empty query and typing "/" leaves a leading slash
        // IN the text; Enter on any such slash-headed text is refused too — the
        // brief's literal "query starting with '/'" rule.
        $picker = $this->press(SessionPicker::new($this->rows()), ['/', 'x']);
        [$cleared] = $picker->handleKey('backspace');
        [$slashed] = $cleared->handleKey('/');
        $this->assertSame('/', $slashed->query());

        [, $action] = $slashed->handleKey('enter');
        $this->assertNotSame('resume', $action, 'a query starting with "/" must never resume');
    }

    public function testFilterEntryItselfIsNotARefusal(): void
    {
        [$picker, $action] = SessionPicker::new($this->rows())->handleKey('/');

        $this->assertSame(SessionListAction::Filter, $action);
        $this->assertNull($picker->notice());
    }
}

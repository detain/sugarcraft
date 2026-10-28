<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui\MessagePicker;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tui\MessagePicker\MessagePicker;
use SugarCraft\Code\Tui\MessagePicker\MessagePickerAction;
use SugarCraft\Core\Util\Width;

/**
 * plan_comparison C-TUI-3.c: the message-picker core. The widget's own
 * rules only — ordering, filter-as-you-type, the accept index staying the
 * store's rewind position through a filter, and the empty session. The idle
 * Esc Esc door and the App slot land with the wiring step (C-TUI-3.d).
 */
final class MessagePickerTest extends TestCase
{
    private string $locale = '';

    protected function setUp(): void
    {
        // The overlay paints English strings; a developer's LANG must not
        // decide these assertions, and the registry is process-global.
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    public function testRowsAreNewestFirstAndNumberedByRewindPosition(): void
    {
        // The store reports newest-first; the first row is one step back.
        $picker = MessagePicker::open(['the freshest prompt', 'an older one', 'the oldest one']);

        $this->assertSame(
            [
                ['stepsBack' => 1, 'prompt' => 'the freshest prompt'],
                ['stepsBack' => 2, 'prompt' => 'an older one'],
                ['stepsBack' => 3, 'prompt' => 'the oldest one'],
            ],
            $picker->matches(),
        );
        $this->assertSame(1, $picker->selectedStepsBack(), 'the highlight starts on the newest checkpoint');
    }

    public function testTypingFiltersByCaseInsensitiveContainmentAndResetsTheHighlight(): void
    {
        $picker = MessagePicker::open(['fix the lexer', 'wrap up the report', 'fix the parser']);
        [$down] = $picker->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->selectedIndex(), 'fixture: moved off the top');

        [$f] = $down->update(new KeyMsg(KeyType::Char, 'F'));
        [$typed] = $f->update(new KeyMsg(KeyType::Char, 'i'));
        [$typed] = $typed->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertSame(['fix the lexer', 'fix the parser'], \array_column($typed->matches(), 'prompt'));
        $this->assertSame(0, $typed->selectedIndex(), 'a new query restarts at the newest match');
        $this->assertSame('Fix', $typed->query(), 'the query keeps the typed case; only the match is folded');
    }

    public function testAcceptReportsTheRowsStorePositionNotItsPlaceInTheHitList(): void
    {
        // 'an older one' sits third in the store; filtering must not make
        // accepting it a one-step rewind.
        $picker = MessagePicker::open(['fix the lexer', 'wrap up the report', 'fix the parser']);
        [$typed] = $picker->update(new KeyMsg(KeyType::Char, 'z'));
        [$typed] = $typed->update(new KeyMsg(KeyType::Backspace));
        foreach (\str_split('fix') as $char) {
            [$typed] = $typed->update(new KeyMsg(KeyType::Char, $char));
        }
        [$moved] = $typed->update(new KeyMsg(KeyType::Down));

        [$next, $action] = $moved->update(new KeyMsg(KeyType::Enter));

        $this->assertNotNull($action, 'Enter on a highlighted row accepts it');
        $this->assertSame(MessagePickerAction::ACCEPT, $action->kind);
        $this->assertSame(3, $action->stepsBack, 'the second hit is the store\'s THIRD row');
        $this->assertSame($picker->rows(), $next->rows(), 'accepting changes nothing by itself — the host acts');
    }

    public function testEscapeClosesWithoutATouchOnTheConversation(): void
    {
        $picker = MessagePicker::open(['only prompt']);

        [$next, $action] = $picker->update(new KeyMsg(KeyType::Escape));

        $this->assertNotNull($action);
        $this->assertSame(MessagePickerAction::CLOSE, $action->kind);
        $this->assertSame(0, $action->stepsBack, 'a close carries no rewind position');
        $this->assertSame($picker, $next, 'close is the widget answer; the host drops the overlay');
    }

    public function testEnterOnANoMatchFilterIsQuiet(): void
    {
        $picker = MessagePicker::open(['alpha', 'beta']);
        [$typed] = $picker->update(new KeyMsg(KeyType::Char, 'q'));
        [$typed] = $typed->update(new KeyMsg(KeyType::Char, 'z'));
        [$typed] = $typed->update(new KeyMsg(KeyType::Char, 'z'));

        $this->assertSame([], $typed->matches(), 'fixture: nothing matches');

        [$next, $action] = $typed->update(new KeyMsg(KeyType::Enter));

        $this->assertNull($action, 'accepting nothing asks the host for nothing');
        $this->assertSame($typed, $next);
    }

    public function testNavigationAndDeletionOnAnEmptySessionStayQuiet(): void
    {
        $picker = MessagePicker::open([]);

        [$moved, $moveAction] = $picker->update(new KeyMsg(KeyType::Down));
        $this->assertSame($picker, $moved, 'there is nothing to move to');
        $this->assertNull($moveAction);

        [$back, $backAction] = $picker->update(new KeyMsg(KeyType::Backspace));
        $this->assertNull($backAction);
        $this->assertSame('', $back->query(), 'backspace at the empty query stays empty');

        [, $enterAction] = $picker->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($enterAction, 'an empty session has no checkpoint to rewind to');

        [, $escAction] = $picker->update(new KeyMsg(KeyType::Escape));
        $this->assertNotNull($escAction);
        $this->assertSame(MessagePickerAction::CLOSE, $escAction->kind, 'Escape still leaves');
    }

    public function testViewNamesTheRowsTheirStepsAndTheEmptyState(): void
    {
        $picker = MessagePicker::open(['ship the release', 'retry the flaky test']);
        $rendered = $picker->view(80, 24, Theme::default());

        $this->assertStringContainsString('Rewind to a message', $rendered);
        $this->assertStringContainsString('ship the release', $rendered);
        $this->assertStringContainsString('retry the flaky test', $rendered);
        $this->assertStringContainsString('1', $rendered);
        $this->assertStringContainsString('2', $rendered);

        $empty = MessagePicker::open([])->view(80, 24, Theme::default());
        $this->assertStringContainsString('(no checkpoints yet)', $empty);
    }

    public function testViewKeepsEveryLineInsideTheTerminal(): void
    {
        $picker = MessagePicker::open(['a prompt long enough to need clipping at the narrow end of the sweep ' . \str_repeat('x', 80)]);

        foreach ([40, 60, 80, 120] as $cols) {
            $rendered = $picker->view($cols, 24, Theme::default());
            foreach (\explode("\n", $rendered) as $line) {
                $plain = (string) \preg_replace('/\x1b\[[0-9;]*m/', '', $line);
                $this->assertLessThanOrEqual($cols, Width::string($plain), 'no line may exceed its terminal at ' . $cols . ' columns');
            }
        }
    }

    public function testSpaceJoinsTheQueryAndUnknownKeysAreIgnored(): void
    {
        $picker = MessagePicker::open(['two words']);
        [$typed] = $picker->update(new KeyMsg(KeyType::Char, 'o'));
        [$typed] = $typed->update(new KeyMsg(KeyType::Space));
        [$typed] = $typed->update(new KeyMsg(KeyType::Char, 'w'));

        $this->assertSame('o w', $typed->query());
        $this->assertSame(['two words'], \array_column($typed->matches(), 'prompt'));

        [$same, $action] = $typed->update(new KeyMsg(KeyType::Tab));
        $this->assertSame($typed, $same, 'an unmapped key is inert');
        $this->assertNull($action);
    }
}

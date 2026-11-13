<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\TextInput\TextInput;

/**
 * History recall contract (SF-40 / sugar-forms-054 + -022).
 *
 * -054: navigating PAST the newest entry used to clear the line — the
 * in-progress draft was destroyed and the docblock's "current input" claim
 * was prose the code contradicted. Shell parity: the first Up captures the
 * draft, the past-newest Down restores it.
 *
 * -022: withHistory/addToHistory lived only on the internal widget, so a
 * consumer driving the documented Field API could never populate history at
 * all. The Input field now carries pass-throughs.
 */
final class TextInputHistoryTest extends TestCase
{
    public function testDraftSurvivesNavigationPastNewest(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['first', 'second'])
            ->setValue('abc')
            ->focus();

        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        self::assertSame('second', $t->value);

        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        self::assertSame('first', $t->value);

        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        self::assertSame('second', $t->value);

        // Past the newest: the draft comes back, cursor parked at its end.
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        self::assertSame('abc', $t->value);
        self::assertSame(-1, $t->historyIndex);
        self::assertNull($t->historyDraft, 'the draft slot is consumed with the browse');
    }

    public function testTypingAfterBrowsingCapturesTheFreshDraft(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['older'])
            ->focus();

        // Empty draft: Up then Down restores the empty current input exactly
        // as before — the pre-SF-40 clear-to-empty behavior is a special case.
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        self::assertSame('older', $t->value);
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        self::assertSame('', $t->value);

        // Type a line, browse, come back: the typed line survives.
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'w'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Char, 'x'));
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        self::assertSame('older', $t->value);
        [$t, ] = $t->update(new KeyMsg(KeyType::Down));
        self::assertSame('wx', $t->value);
    }

    public function testResettingTheHistoryDropsTheStaleDraft(): void
    {
        [$t, ] = TextInput::new()
            ->withHistory(['first'])
            ->setValue('keep-me')
            ->focus();
        [$t, ] = $t->update(new KeyMsg(KeyType::Up));
        self::assertSame('keep-me', $t->historyDraft);

        // A snapshot swap belongs to a new editing session: the OLD draft
        // must never resurrect against the NEW history.
        $t2 = $t->withHistory(['alpha', 'beta']);
        self::assertNull($t2->historyDraft);

        $t3 = $t->addToHistory('gamma');
        self::assertNull($t3->historyDraft);
    }

    public function testFieldLevelWithHistoryAndAddToHistoryReachTheWidget(): void
    {
        $field = Input::new('cmd')
            ->withHistory(['ls -la', 'git status'])
            ->addToHistory('docker ps');

        [$cur, ] = $field->focus();
        [$cur, ] = $cur->update(new KeyMsg(KeyType::Up));
        self::assertSame('docker ps', $cur->value(), 'newest first — recall reaches 022 only via the field API');
        [$cur, ] = $cur->update(new KeyMsg(KeyType::Up));
        self::assertSame('git status', $cur->value());
        [$cur, ] = $cur->update(new KeyMsg(KeyType::Up));
        self::assertSame('ls -la', $cur->value());
    }

    public function testFieldShortFormAndLongFormAgree(): void
    {
        $long  = Input::new('cmd')->withHistory(['a', 'b']);
        $short = Input::new('cmd')->history(['a', 'b']);
        [$longV, ]   = $long->focus();
        [$shortV, ]  = $short->focus();
        [$longV, ]   = $longV->update(new KeyMsg(KeyType::Up));
        [$shortV, ]  = $shortV->update(new KeyMsg(KeyType::Up));
        self::assertSame($longV->value(), $shortV->value());
        self::assertSame('b', $shortV->value(), 'sanity: the recall is live');
    }
}

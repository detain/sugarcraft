<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Confirm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Widgets\Confirm\Confirm;
use SugarCraft\Widgets\Confirm\ConfirmResolvedMsg;

/**
 * P2-3a: Confirm, its single-key and typed-word modes, cancel, and the
 * immutability of every setter.
 */
final class ConfirmTest extends TestCase
{
    private static function key(KeyType $type, string $rune = ''): KeyMsg
    {
        return new KeyMsg($type, $rune);
    }

    private static function char(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune);
    }

    /**
     * Feed keys in order and return the final model plus every Cmd returned.
     *
     * @param list<KeyMsg> $keys
     * @return array{0: Confirm, 1: list<?\Closure>}
     */
    private static function feed(Confirm $c, array $keys): array
    {
        $cmds = [];
        foreach ($keys as $key) {
            [$c, $cmd] = $c->update($key);
            $cmds[] = $cmd;
        }
        return [$c, $cmds];
    }

    /** @return list<KeyMsg> */
    private static function typeWord(string $word): array
    {
        return array_map(
            static fn (string $ch): KeyMsg => $ch === ' ' ? self::key(KeyType::Space) : self::char($ch),
            mb_str_split($word),
        );
    }

    private static function resolvedMsg(?\Closure $cmd): ConfirmResolvedMsg
    {
        self::assertNotNull($cmd);
        $msg = $cmd();
        self::assertInstanceOf(ConfirmResolvedMsg::class, $msg);
        return $msg;
    }

    public function testNewStartsPendingInSingleKeyMode(): void
    {
        $c = Confirm::new('Delete file?');
        self::assertTrue($c->isPending());
        self::assertFalse($c->isConfirmed());
        self::assertFalse($c->isCancelled());
        self::assertFalse($c->isRejected());
        self::assertSame('Delete file?', $c->prompt());
        self::assertSame('', $c->typedConfirm());
        self::assertSame('', $c->typed());
        self::assertNull($c->init());
        self::assertNull($c->subscriptions());
    }

    public function testConstructorIsPrivate(): void
    {
        self::assertTrue((new \ReflectionClass(Confirm::class))->getConstructor()?->isPrivate());
    }

    #[DataProvider('yesKeys')]
    public function testYesKeyConfirmsAndSendsTheResolvedMsg(string $rune): void
    {
        $c = Confirm::new('Delete?');
        [$next, $cmd] = $c->update(self::char($rune));
        self::assertTrue($next->isConfirmed());
        self::assertFalse($next->isPending());
        self::assertTrue($c->isPending(), 'update() leaves the original pending');
        $msg = self::resolvedMsg($cmd);
        self::assertTrue($msg->confirmed);
        self::assertSame('Delete?', $msg->prompt);
    }

    /** @return iterable<string, array{string}> */
    public static function yesKeys(): iterable
    {
        yield 'y' => ['y'];
        yield 'Y' => ['Y'];
    }

    #[DataProvider('noKeys')]
    public function testNoKeyCancels(KeyMsg $key): void
    {
        [$next, $cmd] = Confirm::new('Delete?')->update($key);
        self::assertTrue($next->isCancelled());
        self::assertFalse($next->isConfirmed());
        self::assertFalse(self::resolvedMsg($cmd)->confirmed);
    }

    /** @return iterable<string, array{KeyMsg}> */
    public static function noKeys(): iterable
    {
        yield 'n' => [new KeyMsg(KeyType::Char, 'n')];
        yield 'N' => [new KeyMsg(KeyType::Char, 'N')];
        yield 'escape' => [new KeyMsg(KeyType::Escape)];
    }

    #[DataProvider('ignoredSingleKeys')]
    public function testOtherKeysLeaveTheSingleKeyPromptPending(KeyMsg $key): void
    {
        $c = Confirm::new('Delete?');
        [$next, $cmd] = $c->update($key);
        self::assertSame($c, $next);
        self::assertNull($cmd);
        self::assertTrue($next->isPending());
    }

    /** @return iterable<string, array{KeyMsg}> */
    public static function ignoredSingleKeys(): iterable
    {
        yield 'enter never confirms' => [new KeyMsg(KeyType::Enter)];
        yield 'space' => [new KeyMsg(KeyType::Space)];
        yield 'other rune' => [new KeyMsg(KeyType::Char, 'x')];
        yield 'ctrl+y' => [new KeyMsg(KeyType::Char, 'y', ctrl: true)];
        yield 'alt+y' => [new KeyMsg(KeyType::Char, 'y', alt: true)];
        yield 'release of y' => [new KeyReleaseMsg(KeyType::Char, 'y')];
    }

    public function testNonKeyMessagesAreIgnored(): void
    {
        $c = Confirm::new('Delete?');
        [$next, $cmd] = $c->update(new WindowSizeMsg(80, 24));
        self::assertSame($c, $next);
        self::assertNull($cmd);
    }

    public function testAnAnsweredPromptIgnoresFurtherKeysUntilReset(): void
    {
        [$yes] = Confirm::new('Delete?')->update(self::char('y'));
        [$still, $cmd] = $yes->update(self::char('n'));
        self::assertSame($yes, $still);
        self::assertNull($cmd);
        self::assertTrue($still->isConfirmed());

        [$no] = Confirm::new('Delete?')->update(self::key(KeyType::Escape));
        [$still, $cmd] = $no->update(self::char('y'));
        self::assertSame($no, $still);
        self::assertNull($cmd);
        self::assertTrue($still->isCancelled());
    }

    public function testTypedWordMatchingAndSubmittedConfirms(): void
    {
        $c = Confirm::new('Type delete to remove 3 files')->withTypedConfirm('delete');
        [$typed, $cmds] = self::feed($c, self::typeWord('delete'));
        self::assertSame('delete', $typed->typed());
        self::assertTrue($typed->isPending(), 'typing alone never confirms');
        self::assertSame([null, null, null, null, null, null], $cmds);

        [$done, $cmd] = $typed->update(self::key(KeyType::Enter));
        self::assertTrue($done->isConfirmed());
        $msg = self::resolvedMsg($cmd);
        self::assertTrue($msg->confirmed);
        self::assertSame('Type delete to remove 3 files', $msg->prompt);
    }

    public function testThePlanChainCompiles(): void
    {
        // The plan's call form: new($prompt)->withTypedConfirm('delete')->update($msg), then isConfirmed().
        [$c, $cmd] = Confirm::new('Sure?')->withTypedConfirm('delete')->update(self::char('d'));
        self::assertInstanceOf(Confirm::class, $c);
        self::assertNull($cmd);
        self::assertFalse($c->isConfirmed());
    }

    #[DataProvider('wrongWords')]
    public function testAWrongWordDoesNotConfirm(string $typed): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$c] = self::feed($c, self::typeWord($typed));
        [$after, $cmd] = $c->update(self::key(KeyType::Enter));
        self::assertNull($cmd);
        self::assertFalse($after->isConfirmed());
        self::assertTrue($after->isPending());
        self::assertTrue($after->isRejected());
        self::assertSame($typed, $after->typed(), 'the wrong text stays for correction');
    }

    /** @return iterable<string, array{string}> */
    public static function wrongWords(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix' => ['delet'];
        yield 'longer' => ['deletes'];
        yield 'case differs' => ['Delete'];
        yield 'trailing space' => ['delete '];
        yield 'other word' => ['remove'];
    }

    public function testYDoesNotConfirmInTypedMode(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$c, $cmd] = $c->update(self::char('y'));
        self::assertNull($cmd);
        self::assertTrue($c->isPending());
        self::assertSame('y', $c->typed());
        [$c] = $c->update(self::key(KeyType::Enter));
        self::assertFalse($c->isConfirmed());

        // `n` is text here too, not a cancel.
        [$c] = $c->update(self::char('n'));
        self::assertTrue($c->isPending());
        self::assertSame('yn', $c->typed());
    }

    public function testCorrectingARejectedWordThenConfirms(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$c] = self::feed($c, self::typeWord('delte'));
        [$c] = $c->update(self::key(KeyType::Enter));
        self::assertTrue($c->isRejected());

        [$c] = self::feed($c, [
            self::key(KeyType::Backspace),
            self::key(KeyType::Backspace),
            self::key(KeyType::Backspace),
        ]);
        self::assertFalse($c->isRejected(), 'an edit clears the rejection');
        self::assertSame('de', $c->typed());
        [$c] = self::feed($c, self::typeWord('lete'));
        [$c, $cmd] = $c->update(self::key(KeyType::Enter));
        self::assertTrue($c->isConfirmed());
        self::assertTrue(self::resolvedMsg($cmd)->confirmed);
    }

    public function testBackspaceOnAnEmptyBufferIsANoOpAndHandlesMultibyte(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('löschen');
        [$same, $cmd] = $c->update(self::key(KeyType::Backspace));
        self::assertSame($c, $same);
        self::assertNull($cmd);

        [$c] = self::feed($c, self::typeWord('lö'));
        [$c] = $c->update(self::key(KeyType::Backspace));
        self::assertSame('l', $c->typed());
        [$c] = self::feed($c, self::typeWord('öschen'));
        [$c] = $c->update(self::key(KeyType::Enter));
        self::assertTrue($c->isConfirmed());
    }

    public function testAWordWithASpaceIsTypedWithTheSpaceKey(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('drop table');
        [$c] = self::feed($c, self::typeWord('drop table'));
        [$c] = $c->update(self::key(KeyType::Enter));
        self::assertTrue($c->isConfirmed());
    }

    public function testEscapeCancelsInTypedMode(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$c] = self::feed($c, self::typeWord('delete'));
        [$c, $cmd] = $c->update(self::key(KeyType::Escape));
        self::assertTrue($c->isCancelled());
        self::assertFalse($c->isConfirmed());
        self::assertFalse(self::resolvedMsg($cmd)->confirmed);

        [$after, $cmd] = $c->update(self::key(KeyType::Enter));
        self::assertSame($c, $after, 'a cancelled prompt cannot be confirmed by a late Enter');
        self::assertNull($cmd);
    }

    public function testChordsAndReleasesDoNotTypeInTypedMode(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        foreach ([
            new KeyMsg(KeyType::Char, 'd', ctrl: true),
            new KeyMsg(KeyType::Char, 'd', alt: true),
            new KeyReleaseMsg(KeyType::Char, 'd'),
            new KeyMsg(KeyType::Tab),
            new KeyMsg(KeyType::Left),
        ] as $key) {
            [$next, $cmd] = $c->update($key);
            self::assertSame($c, $next);
            self::assertNull($cmd);
        }
    }

    public function testTypedKeysAreSanitized(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$c] = $c->update(self::char("\x1b]52;c;aGk=\x07"));
        self::assertSame(Sanitize::untrustedForMarkedFrames($c->typed()), $c->typed());
        self::assertStringNotContainsString("\x1b", $c->typed());
        self::assertStringNotContainsString("\x07", $c->typed());
        self::assertStringNotContainsString("\x1b", $c->view());
    }

    public function testWithTypedConfirmRejectsAnEmptyWord(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Confirm::new('Sure?')->withTypedConfirm("\x1b[31m");
    }

    public function testWithTypedConfirmIsImmutableAndClearsTypedText(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$typed] = self::feed($c, self::typeWord('del'));
        $other = $typed->withTypedConfirm('remove');
        self::assertNotSame($typed, $other);
        self::assertSame('remove', $other->typedConfirm());
        self::assertSame('', $other->typed());
        self::assertSame('delete', $typed->typedConfirm(), 'the original keeps its word');
        self::assertSame('del', $typed->typed());
    }

    public function testWithTypedConfirmOnAnAnsweredPromptRearmsIt(): void
    {
        [$yes] = Confirm::new('Delete project?')->update(self::char('y'));
        self::assertTrue($yes->isConfirmed());

        $armed = $yes->withTypedConfirm('delete');
        self::assertTrue($yes->isConfirmed(), 'the original keeps its answer');
        self::assertTrue($armed->isPending());
        self::assertFalse($armed->isConfirmed());
        self::assertSame('', $armed->typed());

        [$c, $cmd] = $armed->update(self::key(KeyType::Enter));
        self::assertNull($cmd);
        self::assertFalse($c->isConfirmed(), 'Enter alone never confirms the new word');
        self::assertTrue($c->isRejected());

        [$c] = self::feed($c, self::typeWord('delete'));
        [$c, $cmd] = $c->update(self::key(KeyType::Enter));
        self::assertTrue($c->isConfirmed());
        self::assertTrue(self::resolvedMsg($cmd)->confirmed);
    }

    public function testWithoutTypedConfirmAndWithPromptRearmAnAnsweredPrompt(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$done] = self::feed($c, [...self::typeWord('delete'), self::key(KeyType::Enter)]);
        self::assertTrue($done->isConfirmed());

        $plain = $done->withoutTypedConfirm();
        self::assertTrue($plain->isPending());
        self::assertSame('', $plain->typed());

        $reprompted = $done->withPrompt('Really?');
        self::assertTrue($reprompted->isPending());
        self::assertSame('', $reprompted->typed(), 'the old word typed for the last answer is gone');
        [$after, $cmd] = $reprompted->update(self::key(KeyType::Enter));
        self::assertNull($cmd);
        self::assertFalse($after->isConfirmed());

        [$no] = Confirm::new('Sure?')->update(self::key(KeyType::Escape));
        self::assertTrue($no->withPrompt('Again?')->isPending());
        self::assertTrue($no->withTypedConfirm('delete')->isPending());
    }

    public function testWithPromptOnAPendingPromptKeepsTheTypedText(): void
    {
        [$c] = self::feed(Confirm::new('Sure?')->withTypedConfirm('delete'), self::typeWord('del'));
        $d = $c->withPrompt('Really?');
        self::assertSame('del', $d->typed());
        self::assertTrue($d->isPending());
    }

    public function testWithoutTypedConfirmIsImmutableAndRestoresSingleKeyMode(): void
    {
        $typed = Confirm::new('Sure?')->withTypedConfirm('delete');
        $plain = $typed->withoutTypedConfirm();
        self::assertNotSame($typed, $plain);
        self::assertSame('', $plain->typedConfirm());
        self::assertSame('delete', $typed->typedConfirm());
        [$plain] = $plain->update(self::char('y'));
        self::assertTrue($plain->isConfirmed());
    }

    public function testWithPromptIsImmutableAndSanitized(): void
    {
        $c = Confirm::new('First');
        $d = $c->withPrompt("Second\nline\x1b[2J");
        self::assertNotSame($c, $d);
        self::assertSame('First', $c->prompt());
        self::assertSame('Second line', $d->prompt());
    }

    public function testResetIsImmutableAndRearmsThePrompt(): void
    {
        $c = Confirm::new('Sure?')->withTypedConfirm('delete');
        [$done] = self::feed($c, [...self::typeWord('delete'), self::key(KeyType::Enter)]);
        self::assertTrue($done->isConfirmed());

        $again = $done->reset();
        self::assertNotSame($done, $again);
        self::assertTrue($done->isConfirmed(), 'the original stays confirmed');
        self::assertTrue($again->isPending());
        self::assertSame('', $again->typed());
        self::assertSame('delete', $again->typedConfirm());
        self::assertSame('Sure?', $again->prompt());
    }

    public function testThePromptAndWordAreSanitizedToOneLine(): void
    {
        $c = Confirm::new("Delete\r\nall?\x1b[31m")->withTypedConfirm("del\tete");
        self::assertSame('Delete all?', $c->prompt());
        self::assertSame('del ete', $c->typedConfirm());
        self::assertSame("Delete all?\n> ", $c->view());
    }

    public function testViewInSingleKeyMode(): void
    {
        $c = Confirm::new('Delete file?');
        self::assertSame('Delete file? [y/n]', $c->view());
        self::assertSame($c->view(), (string) $c);
    }

    public function testViewInTypedModeShowsTheTypedText(): void
    {
        $c = Confirm::new('Type delete to confirm')->withTypedConfirm('delete');
        [$c] = self::feed($c, self::typeWord('del'));
        self::assertSame("Type delete to confirm\n> del", $c->view());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CopyCommand;
use SugarCraft\Code\Commands\Specs\BuiltInCommands;
use SugarCraft\Code\Message;

/**
 * The `/copy [n]` selection core (C-TUI-3.a, GAP-UI-16): bare means the last
 * assistant reply, a number means its nth fenced code block counted from the
 * end. {@see CopyCommand::select()} is pure, so everything here is a direct
 * call — the OSC 52 + system-clipboard legs and the uiOnly notices are
 * pinned in {@see \SugarCraft\Code\Tests\Chat\CopySlashCommandTest}.
 *
 * Fixtures join lines with LF; the walker normalises any newline run. The
 * fence literals are plain backtick strings — no glob shapes (GlobDialect
 * corpus law).
 */
final class CopyCommandTest extends TestCase
{
    private const OPEN = '```php';
    private const CLOSE = '```';

    public function testBareCopySelectsTheLastAssistantReply(): void
    {
        $selection = CopyCommand::select([
            Message::user('first question'),
            Message::assistant('first reply text'),
            Message::user('second question'),
            Message::assistant('second reply text'),
        ], '');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame('second reply text', $selection['text']);
        self::assertSame(0, $selection['blocks']);
    }

    public function testBareCopySkipsEmptyAndUiOnlyAssistantRows(): void
    {
        $history = [
            Message::user('do it'),
            Message::assistant('real reply'),
            // A tool-step row that carried only calls renders as steps, not
            // as a reply; and a uiOnly row is the app talking to the user.
            // Neither is a copy candidate — the search walks past both.
            Message::assistant(''),
            Message::notice('a ui-only row with body text'),
        ];

        $selection = CopyCommand::select($history, '');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame('real reply', $selection['text']);
    }

    public function testNoReplyYetIsStatedNotSilent(): void
    {
        $selection = CopyCommand::select([Message::user('hello')], '');

        self::assertSame(CopyCommand::STATUS_NO_REPLY, $selection['status']);
        self::assertSame('', $selection['text']);
        self::assertSame(0, $selection['blocks']);
    }

    public function testNumberCountsBlocksFromTheEndNewestFirst(): void
    {
        $reply = $this->replyWithBlocks(3);

        foreach ([1 => 'body-3', 2 => 'body-2', 3 => 'body-1'] as $n => $expected) {
            $selection = CopyCommand::select([Message::assistant($reply)], (string) $n);

            self::assertSame(CopyCommand::STATUS_OK, $selection['status'], "/copy {$n} must select");
            self::assertSame($expected, $selection['text']);
            self::assertSame(3, $selection['blocks']);
        }
    }

    public function testBareCopyOfABlockyReplyStillSelectsTheWholeReply(): void
    {
        $reply = $this->replyWithBlocks(2);
        $selection = CopyCommand::select([Message::assistant($reply)], '');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame($reply, $selection['text']);
        self::assertSame(2, $selection['blocks']);
    }

    public function testNumberPastTheBlockCountNamesNoneAndStatesTheRange(): void
    {
        $selection = CopyCommand::select([Message::assistant($this->replyWithBlocks(2))], '5');

        self::assertSame(CopyCommand::STATUS_OUT_OF_RANGE, $selection['status']);
        self::assertSame('', $selection['text']);
        self::assertSame(2, $selection['blocks']);
    }

    public function testNumberWithNoBlocksAtAllIsStatedSeparately(): void
    {
        $selection = CopyCommand::select([Message::assistant('prose only')], '1');

        self::assertSame(CopyCommand::STATUS_NO_BLOCK, $selection['status']);
        self::assertSame(0, $selection['blocks']);
    }

    public function testArgumentMustBeAPositiveWholeNumber(): void
    {
        $reply = $this->replyWithBlocks(1);

        // '0' is a whole number that names no position (1 is the newest) and
        // is refused here, not folded into the range error; the rest are not
        // whole numbers at all.
        foreach (['x', '-1', '0', '1.5', ' 1', '٣'] as $bad) {
            $selection = CopyCommand::select([Message::assistant($reply)], $bad);

            self::assertSame(
                CopyCommand::STATUS_BAD_ARG,
                $selection['status'],
                "argument '{$bad}' is not a positive whole number",
            );
        }
    }

    public function testInfoStringIsContentInsideTheBlockAndTheFenceLinesAreStripped(): void
    {
        $reply = implode("\n", [
            'intro',
            self::OPEN,
            self::CLOSE . 'sh is just a line inside',
            'the code line',
            self::CLOSE,
            'after',
        ]);

        $selection = CopyCommand::select([Message::assistant($reply)], '1');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame(self::CLOSE . 'sh is just a line inside' . "\n" . 'the code line', $selection['text']);
    }

    public function testAShortBacktickRunCannotCloseALongFence(): void
    {
        $reply = implode("\n", [
            '````',
            self::CLOSE,
            'still inside',
            '````',
        ]);

        $selection = CopyCommand::select([Message::assistant($reply)], '1');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame(self::CLOSE . "\n" . 'still inside', $selection['text']);
        self::assertSame(1, $selection['blocks']);
    }

    public function testTildeFencesAreNotFences(): void
    {
        $reply = implode("\n", ['~~~', 'tilde block', '~~~']);

        $selection = CopyCommand::select([Message::assistant($reply)], '1');
        self::assertSame(CopyCommand::STATUS_NO_BLOCK, $selection['status']);

        $bare = CopyCommand::select([Message::assistant($reply)], '');
        self::assertSame(CopyCommand::STATUS_OK, $bare['status']);
        self::assertSame($reply, $bare['text']);
    }

    public function testUnterminatedBlockRunsToTheEndOfTheReply(): void
    {
        $reply = implode("\n", ['prose', self::CLOSE, 'tail-a', 'tail-b']);

        $selection = CopyCommand::select([Message::assistant($reply)], '1');

        self::assertSame(CopyCommand::STATUS_OK, $selection['status']);
        self::assertSame('tail-a' . "\n" . 'tail-b', $selection['text']);
    }

    public function testAFencePairAroundNothingIsNotACopyCandidate(): void
    {
        $reply = implode("\n", ['a', self::OPEN, self::CLOSE, 'b']);

        $selection = CopyCommand::select([Message::assistant($reply)], '1');
        self::assertSame(CopyCommand::STATUS_NO_BLOCK, $selection['status']);

        $bare = CopyCommand::select([Message::assistant($reply)], '');
        self::assertSame(CopyCommand::STATUS_OK, $bare['status']);
        self::assertSame(0, $bare['blocks']);
    }

    // ---- registration + the read-only law ------------------------------------

    public function testTheSpecRowIsRegisteredClientOnlyWithTheChatHandler(): void
    {
        $spec = BuiltInCommands::forSpelling('copy');

        self::assertNotNull($spec, '/copy must have a spec file under builtin-commands/');
        self::assertSame('handleCopyCommand', $spec->handler, 'the typed verb must reach Chat::handleCopyCommand()');
        self::assertNull(
            $spec->instantiateHostCommand(),
            "/copy is client-only ON PURPOSE: a serve head would answer for the wrong machine's clipboard",
        );
    }

    public function testCopyIsAReadOnlySafeCommand(): void
    {
        $allowlist = (new \ReflectionClass(Chat::class))
            ->getReflectionConstant('READ_ONLY_COMMANDS')
            ->getValue();

        self::assertContains('copy', $allowlist, '/copy writes a clipboard, not the session — it must answer in a read-only window');
    }

    // ---- fixtures -------------------------------------------------------------

    private function replyWithBlocks(int $count): string
    {
        $lines = ['prose above the blocks'];
        for ($i = 1; $i <= $count; $i++) {
            $lines[] = self::OPEN;
            $lines[] = "body-{$i}";
            $lines[] = self::CLOSE;
        }

        return implode("\n", $lines);
    }
}

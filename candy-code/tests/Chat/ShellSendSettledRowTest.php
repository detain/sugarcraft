<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\ShellSendSettledMsg;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;

/**
 * A `/shells send` that waited for its relay answers with a
 * {@see ShellSendSettledMsg} (P4-12 CC-1). The Chat arm appends that row
 * as-is: the wording and the error flag were decided where the send happened,
 * so the arm must not re-word or re-flag it.
 */
final class ShellSendSettledRowTest extends TestCase
{
    public function testTheSettledRowIsAppendedAsItsOwnUiOnlyRow(): void
    {
        $row = Message::assistant('Typed into shell sh-0badf00d (4 bytes).')->withUiOnly();
        [$next, $cmd] = (new Chat())->update(new ShellSendSettledMsg($row, null));

        $this->assertNull($cmd, 'the arm settles the row; it starts nothing');
        $last = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $last->role);
        $this->assertSame('Typed into shell sh-0badf00d (4 bytes).', $last->content);
        $this->assertFalse($last->error, 'a sent line is not an error row');
    }

    public function testARefusedSendKeepsItsErrorFlag(): void
    {
        $row = Message::assistant('the relay for shell sh-0badf00d had not opened its input within 2000 ms.')
            ->withUiOnly()
            ->withError();
        [$next] = (new Chat())->update(new ShellSendSettledMsg($row, null));

        $last = $next->history[count($next->history) - 1];
        $this->assertTrue($last->error, 'the producer\'s verdict travels with the row');
    }

    public function testTheSettledRowLandsInTheSessionItWasTypedIn(): void
    {
        $row = Message::assistant('Typed into shell sh-0badf00d (4 bytes).')->withUiOnly();
        [$next] = (new Chat(currentSessionId: 'session-A'))->update(new ShellSendSettledMsg($row, 'session-A'));

        $last = $next->history[count($next->history) - 1];
        $this->assertSame('Typed into shell sh-0badf00d (4 bytes).', $last->content);
    }

    public function testASettledRowForAnotherSessionIsDroppedNotAppended(): void
    {
        $row = Message::assistant('Typed into shell sh-0badf00d (4 bytes).')->withUiOnly();
        $chat = new Chat(currentSessionId: 'session-B');
        [$next, $cmd] = $chat->update(new ShellSendSettledMsg($row, 'session-A'));

        $this->assertNull($cmd, 'a dropped row starts nothing');
        $this->assertSame($chat->history, $next->history, 'the row must not land in the session now on screen');
    }
}

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
        [$next, $cmd] = (new Chat())->update(new ShellSendSettledMsg($row));

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
        [$next] = (new Chat())->update(new ShellSendSettledMsg($row));

        $last = $next->history[count($next->history) - 1];
        $this->assertTrue($last->error, 'the producer\'s verdict travels with the row');
    }
}

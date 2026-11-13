<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\BuiltIn\Bash;

/**
 * #4: a Bash result carries the process exit status as structure, so the
 * transcript can print `exit N`. The text the model reads is unchanged, and a
 * result that never ran a process reports no code.
 */
final class BashExitCodeTest extends TestCase
{
    public function testASuccessfulCommandReportsZero(): void
    {
        $result = (new Bash())->execute(['command' => 'echo ok', 'id' => 'c_ok']);

        self::assertFalse($result->isError());
        self::assertSame(0, $result->exitCode());
        self::assertStringContainsString('ok', $result->content());
    }

    public function testAFailingCommandReportsItsOwnExitStatus(): void
    {
        $result = (new Bash())->execute(['command' => 'echo nope; exit 3', 'id' => 'c_fail']);

        self::assertTrue($result->isError());
        self::assertSame(3, $result->exitCode());
        self::assertStringContainsString('nope', $result->content(), 'the output the model reads is still the command output');
    }

    public function testTheExitCodeIsStructureNotTextTheModelSeesAsNew(): void
    {
        $result = (new Bash())->execute(['command' => 'exit 5', 'id' => 'c_five']);

        self::assertStringNotContainsString('exit 5', $result->content(), 'the code rides the field, not the text');
        self::assertSame(5, $result->exitCode());
    }
}

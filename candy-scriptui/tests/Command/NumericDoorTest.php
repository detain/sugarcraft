<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Command\ChooseCommand;
use SugarCraft\ScriptUi\Command\FileCommand;
use SugarCraft\ScriptUi\Command\FilterCommand;
use SugarCraft\ScriptUi\Command\InputCommand;
use SugarCraft\ScriptUi\Command\PagerCommand;
use SugarCraft\ScriptUi\Command\StyleCommand;
use SugarCraft\ScriptUi\Command\TableCommand;
use SugarCraft\ScriptUi\Command\WriteCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scriptui-29: every int-ish flag on every command goes through the
 * strict numeric door — a typo'd value refuses with exit 1 before the
 * command does any work, instead of (int) casting into a different mode.
 */
final class NumericDoorTest extends TestCase
{
    /**
     * @return array<string, array{0: Command, 1: array<string,mixed>, 2: list<string>}>
     */
    public static function doorProvider(): array
    {
        return [
            'choose limit'   => [new ChooseCommand(), ['options' => ['apple'], '--limit' => 'two', '--select-if-one' => true], []],
            'choose height'  => [new ChooseCommand(), ['options' => ['apple'], '--height' => 'abc'], []],
            'choose width'   => [new ChooseCommand(), ['options' => ['apple'], '--width' => '-1'], []],
            'filter limit'   => [new FilterCommand(), ['--limit' => 'two'], ["apple\nbanana\n"]],
            'filter height'  => [new FilterCommand(), ['--height' => 'x'], ["apple\nbanana\n"]],
            'input charlimit' => [new InputCommand(), ['--char-limit' => 'two'], []],
            'input width'    => [new InputCommand(), ['--width' => 'ten'], []],
            'write width'    => [new WriteCommand(), ['--width' => 'wide'], []],
            'write maxlines' => [new WriteCommand(), ['--max-lines' => 'two'], []],
            'write charlimit' => [new WriteCommand(), ['--char-limit' => 'many'], []],
            'write height'   => [new WriteCommand(), ['--height' => 'tall'], []],
            'pager width'    => [new PagerCommand(), ['--width' => 'x'], ["body\n"]],
            'pager height'   => [new PagerCommand(), ['--height' => 'x'], ["body\n"]],
            'style width'    => [new StyleCommand(), ['--width' => 'wide'], ['text']],
            'style height'   => [new StyleCommand(), ['--height' => 'tall'], ['text']],
            'file height'    => [new FileCommand(), ['--height' => 'x'], []],
            'table height'   => [new TableCommand(), ['--height' => 'x'], ["a,b\n"]],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @param list<string>        $chunkLines
     *
     * @dataProvider doorProvider
     */
    public function testTypoNumbersRefuse(Command $command, array $options, array $chunkLines): void
    {
        $tester = new CommandTester($command);
        if ($chunkLines !== []) {
            $tester->setInputs($chunkLines);
        }

        $this->assertSame(
            Command::FAILURE,
            $tester->execute($options),
            'A typo\'d numeric must refuse with exit 1, not cast into another mode.'
        );
    }

    /** @dataProvider doorProvider */
    public function testRefusalHappensBeforeAnySuccessPath(Command $command, array $options, array $chunkLines): void
    {
        $tester = new CommandTester($command);
        if ($chunkLines !== []) {
            $tester->setInputs($chunkLines);
        }
        $tester->execute($options);

        // The data channel stays empty: refusal happens before work.
        $this->assertSame('', trim($tester->getDisplay()));
    }

    public function testValidLimitStillWorksByteForByte(): void
    {
        $tester = new CommandTester(new ChooseCommand());

        $this->assertSame(
            Command::SUCCESS,
            $tester->execute(['options' => ['apple'], '--limit' => '2', '--select-if-one' => true])
        );
        $this->assertSame("apple\n", $tester->getDisplay());
    }
}

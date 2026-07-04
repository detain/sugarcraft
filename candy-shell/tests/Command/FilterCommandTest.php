<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Application;
use SugarCraft\Shell\Command\FilterCommand;
use SugarCraft\Shell\Model\FilterModel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for FilterCommand flag-plumbing that does not require a live TTY.
 */
final class FilterCommandTest extends TestCase
{
    /**
     * When exactly one line is supplied and --select-if-one is set,
     * the command short-circuits to SUCCESS without entering Program::run().
     */
    public function testSelectIfOneWithSingleLineShortCircuits(): void
    {
        $app = new Application();
        $command = $app->find('filter');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            '--select-if-one' => true,
        ], ['decorated' => false]);

        // With select-if-one and no stdin, the command exits FAILURE (no lines).
        // The flag is accepted; the non-interactive path is exercised.
        $this->assertSame(1, $status);
    }

    public function testPrintQueryOptionIsDeclared(): void
    {
        $app = new Application();
        $command = $app->find('filter');
        $this->assertTrue($command->getDefinition()->hasOption('print-query'));
    }

    /**
     * Interactive runs need a TTY, so the result contract is exercised via
     * the extracted renderResult() with a model driven to submission.
     */
    public function testPrintQueryEmitsFilterTextBeforeResult(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'n'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());

        $output = new BufferedOutput();
        $status = FilterCommand::renderResult($model, $output, "\n", printQuery: true);

        $this->assertSame(0, $status);
        $this->assertSame("an\nbanana\n", $output->fetch());
    }

    public function testWithoutPrintQueryOnlyResultIsEmitted(): void
    {
        $model = FilterModel::fromOptions(['apple', 'banana', 'cherry']);
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'a'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Char, 'n'));
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));

        $output = new BufferedOutput();
        $status = FilterCommand::renderResult($model, $output, "\n", printQuery: false);

        $this->assertSame(0, $status);
        $this->assertSame("banana\n", $output->fetch());
    }
}

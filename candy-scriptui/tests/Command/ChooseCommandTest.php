<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Command\ChooseCommand;
use SugarCraft\ScriptUi\Model\ChooseModel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for ChooseCommand flag-plumbing that does not require a live TTY.
 */
final class ChooseCommandTest extends TestCase
{
    /**
     * When exactly one option is supplied and --select-if-one is set,
     * the command short-circuits to SUCCESS without entering Program::run().
     * This is the primary non-interactive code path exercised by the flag.
     */
    public function testSelectIfOneShortCircuitsWithSingleOption(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
        ], ['decorated' => false]);

        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }

    /**
     * --cursor-prefix is accepted as a valid option (aliasing --cursor).
     * The alias resolution is tested by verifying the flag is parsed without
     * error even when combined with --select-if-one.
     */
    public function testCursorPrefixFlagIsAccepted(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
            '--cursor-prefix' => '[CURSOR] ',
        ], ['decorated' => false]);

        // With --select-if-one the short-circuit fires; cursor-prefix is accepted.
        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }

    public function testNoSelectedOptionIsDeclared(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $this->assertTrue($command->getDefinition()->hasOption('no-selected'));
    }

    /**
     * Interactive runs need a TTY, so the result contract is exercised via
     * the extracted renderResult() with a model driven to the abort state.
     */
    public function testNoSelectedPrintsMessageAndFailsOnAbort(): void
    {
        $model = ChooseModel::fromOptions(['a', 'b']);
        [$model, ] = $model->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($model->isAborted());

        $output = new BufferedOutput();
        $status = ChooseCommand::renderResult($model, $output, "\n", 'nothing chosen');

        $this->assertSame(1, $status);
        $this->assertSame("nothing chosen\n", $output->fetch());
    }

    public function testNoSelectedPrintsMessageOnEmptyMultiSubmit(): void
    {
        // Multi mode, Enter with nothing ticked: REFUSED (scriptui-22,
        // gate G3 default ship) — the --no-selected message lane keeps
        // its wording and rc 1.
        $model = ChooseModel::fromOptions(['a', 'b'], limit: 2);
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isRefusedEmpty());
        $this->assertFalse($model->isSubmitted());
        $this->assertSame([], $model->selectedAll());

        $output = new BufferedOutput();
        $status = ChooseCommand::renderResult($model, $output, "\n", 'nothing chosen');

        $this->assertSame(1, $status);
        $this->assertSame("nothing chosen\n", $output->fetch());
    }

    public function testEmptyMultiEnterRefusesInsteadOfLegacySuccess(): void
    {
        // DISCLOSED contract flip (scriptui-22 / gate G3 default): this
        // pin used to certify the blank-line + exit 0 "legacy success".
        // An empty answer from a multi choose is now a refusal — a
        // pipeline must never read "chose nothing" as "chose the empty
        // string" (rm $files with nothing in $files).
        $model = ChooseModel::fromOptions(['a', 'b'], limit: 2);
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));

        $output = new BufferedOutput();
        $status = ChooseCommand::renderResult($model, $output, "\n", null);

        $this->assertSame(1, $status);
        $this->assertSame('', $output->fetch());
    }

    public function testRenderResultPrintsSelectionOnSubmit(): void
    {
        $model = ChooseModel::fromOptions(['alpha', 'beta']);
        [$model, ] = $model->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($model->isSubmitted());

        $output = new BufferedOutput();
        $status = ChooseCommand::renderResult($model, $output, "\n", 'nothing chosen');

        $this->assertSame(0, $status);
        $this->assertSame("alpha\n", $output->fetch());
    }

    /**
     * --unselected-prefix is accepted as a valid option.
     */
    public function testUnselectedPrefixFlagIsAccepted(): void
    {
        $app = new Application();
        $command = $app->find('choose');
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'options' => ['only-option'],
            '--select-if-one' => true,
            '--unselected-prefix' => '[ ] ',
        ], ['decorated' => false]);

        $this->assertSame(0, $status);
        $this->assertSame('only-option', trim($tester->getDisplay()));
    }
}

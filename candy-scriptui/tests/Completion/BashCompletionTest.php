<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Application;
use SugarCraft\ScriptUi\Completion\BashCompletion;

final class BashCompletionTest extends TestCase
{
    public function testIsSupportedShell(): void
    {
        $this->assertTrue(BashCompletion::isSupportedShell('bash'));
        $this->assertFalse(BashCompletion::isSupportedShell('zsh'));
        $this->assertFalse(BashCompletion::isSupportedShell('fish'));
    }

    public function testValidShells(): void
    {
        $this->assertSame(['bash'], BashCompletion::validShells());
    }

    public function testGenerateOutputsCompleteFunction(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('complete -F', $script);
    }

    public function testGenerateIncludesRootCommands(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('style', $script);
        $this->assertStringContainsString('completion', $script);
    }

    public function testGenerateContainsCaseStatement(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('case', $script);
        $this->assertStringContainsString('compgen', $script);
    }

    public function testGenerateIncludesCommandOptions(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $this->assertStringContainsString('--foreground', $script);
        $this->assertStringContainsString('--bold', $script);
    }

    public function testScriptIsValidBashSyntax(): void
    {
        $app = new Application();
        $gen = new BashCompletion();
        $script = $gen->generate($app);

        $tmpFile = sys_get_temp_dir() . '/bash_comp_test.sh';
        file_put_contents($tmpFile, $script);

        $result = proc_close(proc_open(
            "bash -n {$tmpFile}",
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        ));

        unlink($tmpFile);
        $this->assertSame(0, $result);
    }

    /**
     * scriptui-23 — golden execution test: source the generated handler
     * under bash, invoke it exactly as `complete -F` would (NAME CUR
     * WORDS INDEX), and require a NON-EMPTY COMPREPLY. The old `${1:-}`
     * read the command name as the completion word, so compgen filtered
     * every branch by the literal app name and completion was inert.
     */
    public function testHandlerCompletesFlagBranch(): void
    {
        $script = (new BashCompletion())->generate(new Application());
        // The filter command's own flag list as the script ships it.
        $this->assertMatchesRegularExpression('/"filter"\)/', $script);

        $out = $this->runHandler($script, 'candy-scriptui filter --', 3, 'candy-scriptui', '--');

        $this->assertNotSame('', trim($out), 'flag branch produced empty COMPREPLY');
        $this->assertMatchesRegularExpression('/^--\S+/m', trim($out), 'flag branch must yield flag candidates');
    }

    public function testHandlerCompletesCommandNameBranch(): void
    {
        $script = (new BashCompletion())->generate(new Application());

        $out = $this->runHandler($script, 'candy-scriptui st', 1, 'candy-scriptui', 'st');

        $this->assertStringContainsString('style', trim($out), 'command-name branch must complete "st" to "style"');
    }

    /**
     * Source the generated completion script and call its handler with
     * the bash-completion contract, echoing one candidate per line.
     */
    private function runHandler(string $script, string $words, int $cword, string $name, string $cur): string
    {
        $scriptFile = sys_get_temp_dir() . '/bash_comp_run_' . getmypid() . '.sh';
        $runnerFile = sys_get_temp_dir() . '/bash_comp_runner_' . getmypid() . '.sh';
        $fn = $this->handlerName($script);
        file_put_contents($scriptFile, $script);
        file_put_contents(
            $runnerFile,
            "source " . escapeshellarg($scriptFile) . "\n"
            . "COMP_WORDS=({$words})\n"
            . "COMP_CWORD={$cword}\n"
            . "{$fn} " . escapeshellarg($name) . ' ' . escapeshellarg($cur) . ' ' . escapeshellarg($words) . " {$cword}\n"
            . "printf '%s\\n' \"\${COMPREPLY[@]}\"\n"
        );

        $proc = proc_open(
            'bash ' . escapeshellarg($runnerFile),
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes
        );
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        unlink($scriptFile);
        unlink($runnerFile);

        $this->assertSame(0, $rc, 'bash runner must exit clean: ' . $out);
        return $out;
    }

    private function handlerName(string $script): string
    {
        $this->assertSame(1, preg_match('/^(_\w+)\(\) \{/m', $script, $m), 'generated script must define a handler');
        return $m[1];
    }
}

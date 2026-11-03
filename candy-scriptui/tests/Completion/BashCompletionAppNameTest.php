<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Completion;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Completion\BashCompletion;
use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * The bash script binds completion with `complete -F <fn> <word>`: both
 * the handler name and the command word must follow the application, or
 * a renamed/branded binary (vendor/bin invocations of a phar or a
 * repacked app) silently registers a function nobody calls. The default
 * spelling stays byte-identical: 'candy-scriptui' -> '_candy_scriptui_main'.
 */
final class BashCompletionAppNameTest extends TestCase
{
    public function testDefaultBinaryKeepsTheHistoricFunctionBytes(): void
    {
        $script = (new BashCompletion())->generate(new \SugarCraft\ScriptUi\Application());

        $this->assertStringContainsString('_candy_scriptui_main() {', $script);
        $this->assertStringContainsString('complete -F _candy_scriptui_main candy-scriptui', $script);
    }

    public function testRenamedApplicationGetsItsOwnFunctionAndWord(): void
    {
        $script = (new BashCompletion())->generate(new SymfonyApplication('tool-name', '1'));

        $this->assertStringContainsString('_tool_name_main() {', $script);
        $this->assertStringContainsString('complete -F _tool_name_main tool-name', $script);
        $this->assertStringNotContainsString('_candy_scriptui_main', $script);
    }
}

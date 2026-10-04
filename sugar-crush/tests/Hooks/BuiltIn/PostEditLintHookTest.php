<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\BuiltIn\PostEditLintHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Lint\LintRunner;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Step 3.E: after a `Write`/`Edit`, the built-in `PostToolUse` lint runs the
 * file's linter (`php -l` by default, the user-tier `lintCommands` map
 * otherwise) and hands the model Aider's `█`-marked report as the call's
 * `additionalContext` — never a refusal.
 *
 * @see PostEditLintHook
 * @see LintRunner
 */
final class PostEditLintHookTest extends TestCase
{
    use HomeSandboxTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/sc-lint-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/src/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/src');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testItIsABoundedPostToolUseHookOnTheTwoEditingTools(): void
    {
        $hook = new PostEditLintHook(LintRunner::new());

        $this->assertSame('post-edit-lint', $hook->name());
        $this->assertSame(HookEvent::PostToolUse, $hook->event());
        $this->assertInstanceOf(BoundedHookInterface::class, $hook);
        foreach (['Write' => 1, 'Edit' => 1, 'Read' => 0, 'Bash' => 0, 'WriteFile' => 0] as $tool => $matches) {
            $this->assertSame($matches, preg_match('/' . $hook->matcher() . '/', $tool), "matcher vs {$tool}");
        }
    }

    public function testACleanPhpFileAddsNothing(): void
    {
        $this->put('src/Ok.php', "<?php\n\$x = 1;\n");

        $result = (new PostEditLintHook(LintRunner::new()))->execute($this->context('Write', 'src/Ok.php'));

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->additionalContext, 'a clean lint must leave the tool result byte-identical');
    }

    public function testABrokenPhpFileGetsAidersMarkedReportAndIsNeverRefused(): void
    {
        $this->put('src/Bad.php', "<?php\n\$x = 1;\n\$y = 2\n}\n");

        $result = (new PostEditLintHook(LintRunner::new()))->execute($this->context('Edit', 'src/Bad.php'));

        $this->assertTrue($result->permitsExecution(), 'a lint is advice: refusing would withhold the edit\'s own output');
        $this->assertSame('', $result->message);
        $note = $result->additionalContext;
        $this->assertStringStartsWith("# Fix any errors below, if possible.\n\n## Running: ", $note);
        $this->assertStringContainsString("-l 'src/Bad.php'", $note, 'the linter is handed the path relative to the project root');
        $this->assertStringContainsString("Unmatched '}'", $note);
        $this->assertStringContainsString("## See relevant line below marked with █.\n\nsrc/Bad.php:\n", $note);
        $this->assertStringContainsString("1│<?php\n2│\$x = 1;\n3│\$y = 2\n4█}\n", $note);
        $this->assertStringNotContainsString('5│', $note, 'a final newline ends the last line, it does not open an empty one');
    }

    public function testAnAbsolutePathInsideTheRootIsShownRelative(): void
    {
        $this->put('src/Bad.php', "<?php\n}\n");

        $result = (new PostEditLintHook(LintRunner::new()))->execute($this->context('Write', $this->root . '/src/Bad.php'));

        $this->assertStringContainsString("src/Bad.php:\n", $result->additionalContext);
        $this->assertStringNotContainsString($this->root, $result->additionalContext);
    }

    public function testNothingToLintIsABareAllow(): void
    {
        $this->put('src/notes.txt', "hello\n");
        $hook = new PostEditLintHook(LintRunner::new());

        foreach ([
            'no file_path' => new HookContext('s', 'Write', [], '{}', '', 'm', 'p', $this->root),
            'no linter for the extension' => $this->context('Write', 'src/notes.txt'),
            'the file is not there' => $this->context('Edit', 'src/Missing.php'),
        ] as $case => $context) {
            $result = $hook->execute($context);
            $this->assertTrue($result->isAllowed(), $case);
            $this->assertSame('', $result->additionalContext, $case);
        }
    }

    /**
     * A `PostToolUse` chain runs after an edit the tool REFUSED too, and a
     * linter quotes the lines it flags — so the lint is jailed exactly as the
     * edit was, or a refused edit outside the workspace would leak lines of
     * the file it was refused.
     */
    public function testAFileOutsideTheProjectRootIsNeverLinted(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside . '/repo', 0o700, true);
        file_put_contents($outside . '/Secret.php', "<?php
\$token = 'abc'
}
");
        $context = static fn (string $path): HookContext => new HookContext(
            's',
            'Edit',
            ['file_path' => $path],
            '{}',
            '',
            'm',
            'p',
            $outside . '/repo',
        );

        try {
            foreach ([$outside . '/Secret.php', '../Secret.php'] as $path) {
                $result = (new PostEditLintHook(LintRunner::new()))->execute($context($path));
                $this->assertTrue($result->isAllowed(), $path);
                $this->assertSame('', $result->additionalContext, "{$path} is outside the jail and must not be read");
            }
        } finally {
            @unlink($outside . '/Secret.php');
            @rmdir($outside . '/repo');
            @rmdir($outside);
        }
    }

    public function testTheUsersMapAddsALinterAndMarksTheLinesItNames(): void
    {
        $this->put('src/notes.txt', "one\ntwo\nthree\n");
        $runner = LintRunner::new()->withCommands([
            '.TXT' => 'sh -c \'echo "$1:2: trailing nonsense" >&2; exit 1\' lint',
        ]);

        $note = (new PostEditLintHook($runner))->execute($this->context('Write', 'src/notes.txt'))->additionalContext;

        $this->assertStringContainsString("src/notes.txt:2: trailing nonsense", $note);
        $this->assertStringContainsString("1│one\n2█two\n3│three\n", $note);
    }

    public function testThePlaceholderPutsTheFileWhereTheCommandSays(): void
    {
        $this->put('src/a.txt', "x\n");
        $runner = LintRunner::new()->withCommands(['txt' => 'printf "%s:1: placed\n" {file} >&2; exit 3']);

        $note = (new PostEditLintHook($runner))->execute($this->context('Write', 'src/a.txt'))->additionalContext;

        $this->assertStringContainsString("printf \"%s:1: placed\\n\" 'src/a.txt' >&2; exit 3", $note);
        $this->assertStringContainsString('1█x', $note);
    }

    public function testTheBuiltInPhpEntryCanBeSwitchedOffOrReplaced(): void
    {
        $this->put('src/Bad.php', "<?php\n}\n");

        foreach ([false, null, '', '  '] as $off) {
            $this->assertNull(LintRunner::new()->withCommands(['php' => $off])->commandFor('src/Bad.php'));
        }
        $this->assertSame('mylint', LintRunner::new()->withCommands(['php' => 'mylint'])->commandFor('x.PHP'));
    }

    public function testAnUnusableMapIsIgnoredNotRefused(): void
    {
        $defaults = LintRunner::defaultCommands();

        foreach (['a string', 42, ['php', 'py'], null] as $settings) {
            $this->assertSame($defaults, LintRunner::new()->withCommands($settings)->commands());
        }
        $this->assertSame($defaults, LintRunner::new()->withCommands(['py' => ['flake8']])->commands(), 'a non-string command is dropped');
    }

    public function testALinterThatCannotStartSaysTheFileWasNotChecked(): void
    {
        $this->put('src/a.txt', "x\n");
        $runner = LintRunner::new()->withCommands(['txt' => 'sc-no-such-linter-' . getmypid()]);

        $result = (new PostEditLintHook($runner))->execute($this->context('Write', 'src/a.txt'));

        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('could not run (exit 127), so src/a.txt was not checked', $result->additionalContext);
        $this->assertStringNotContainsString('# Fix any errors', $result->additionalContext, 'a missing linter is not an error in the file');
    }

    public function testAHungLinterIsStoppedAtItsBudgetAndStillAllows(): void
    {
        $this->put('src/a.txt', "x\n");
        $hook = new PostEditLintHook(LintRunner::new()->withCommands(['txt' => 'sleep 20;'])->withTimeout(0.3));

        $started = microtime(true);
        $result = $hook->execute($this->context('Write', 'src/a.txt'));

        $this->assertLessThan(10.0, microtime(true) - $started);
        $this->assertTrue($result->isAllowed());
        $this->assertStringContainsString('did not finish within 0.3 seconds and was stopped, so src/a.txt was not checked', $result->additionalContext);
    }

    public function testTheChainMayShortenItsBoundButNeverLengthenIt(): void
    {
        $hook = new PostEditLintHook(LintRunner::new()->withTimeout(5.0));

        $this->assertSame(5.0, $hook->timeoutSeconds());
        $this->assertSame(2.0, $hook->withTimeoutSeconds(2.0)->timeoutSeconds());
        $this->assertSame(5.0, $hook->withTimeoutSeconds(60.0)->timeoutSeconds());
    }

    public function testTheReportReachesTheChainsNote(): void
    {
        $this->put('src/Bad.php', "<?php\n}\n");
        $manager = new HookManager(new HookRegistry());
        $manager->register(new PostEditLintHook(LintRunner::new()));

        $result = $manager->postToolUse($this->context('Write', 'src/Bad.php')->withToolOutput('File written'));

        $this->assertTrue($result->permitsExecution());
        $this->assertStringContainsString('2█}', $result->additionalContext);
    }

    /**
     * The launch chain carries the hook, built from the user's `lintCommands`.
     */
    public function testBootstrapRegistersItWithTheUsersMap(): void
    {
        $home = $this->root . '/home';
        mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', (string) json_encode(['lintCommands' => ['txt' => 'mylint', 'php' => false]]));
        chmod($home . '/.sugar-crush/config.json', 0o600);
        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);

        try {
            $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, null);
        } finally {
            ProcessContainment::useSecretEnvAllowlist([]);
            $this->restoreHomeSandbox();
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }

        $this->assertInstanceOf(HookManager::class, $hooks);
        $lint = $hooks->hook(HookEvent::PostToolUse->value, PostEditLintHook::NAME);
        $this->assertInstanceOf(PostEditLintHook::class, $lint);
        $this->assertSame('mylint', $lint->runner()->commandFor('a.txt'));
        $this->assertNull($lint->runner()->commandFor('a.php'), 'the user switched the built-in php entry off');
    }

    private function put(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private function context(string $tool, string $path): HookContext
    {
        $args = ['file_path' => $path];

        return new HookContext('s', $tool, $args, (string) json_encode($args), '', 'm', 'p', $this->root);
    }
}

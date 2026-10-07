<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

/**
 * Audit F-J3: a path-scoped rule must judge the file the TOOL will open, not
 * the spelling the model chose.
 *
 * Measured before the fix with the audit's `r11_rules.php`: under
 * `bypass-permissions` and `Deny Read(/proj/secret.txt)`, `/proj/secret.txt`
 * was Deny but `secret.txt` and `./secret.txt` were ALLOW — the tools resolve a
 * relative path against `--root`, the matcher never did — and a symlink
 * `notes -> secret.txt` walked past every path deny because nothing consulted
 * the filesystem.
 *
 * Deny is observed under {@see PermissionMode::BypassPermissions}, where
 * only a rule can refuse; Ask under {@see PermissionMode::Default}, whose
 * evaluator auto-allows reads so the Ask can only come from the rule (since
 * the owner ruling of 2026-10-06 bypass suppresses Ask-class rules outright);
 * Allow under {@see PermissionMode::DontAsk}, whose evaluator denies `Write`,
 * so a grant can only come from the rule.
 *
 * Fixture, under a fresh temp dir:
 *
 *     real/secret.txt
 *     real/notes          -> secret.txt          (file link)
 *     real/sub/
 *     real/secrets/key
 *     real/vault          -> secrets             (directory link)
 *     real/ghost          -> future.txt          (dangling link)
 *     real/public/ok.txt
 *     real/public/escape  -> ../secret.txt       (link out of the allowed tree)
 *     real/public/dangle  -> ../future.txt       (dangling link out of it)
 *     link                -> real                (symlinked root)
 */
final class PathRuleRespellingTest extends TestCase
{
    private string $base;
    private string $real;
    private string $link;

    protected function setUp(): void
    {
        $tmp = realpath(sys_get_temp_dir());
        self::assertIsString($tmp);
        $this->base = $tmp . '/perm-respell-' . bin2hex(random_bytes(6));
        $this->real = $this->base . '/real';
        $this->link = $this->base . '/link';

        mkdir($this->real . '/sub', 0o777, true);
        mkdir($this->real . '/secrets');
        mkdir($this->real . '/public');
        file_put_contents($this->real . '/secret.txt', 'top secret');
        file_put_contents($this->real . '/secrets/key', 'k');
        file_put_contents($this->real . '/public/ok.txt', 'ok');
        symlink('secret.txt', $this->real . '/notes');
        symlink('secrets', $this->real . '/vault');
        symlink('future.txt', $this->real . '/ghost');
        symlink('../secret.txt', $this->real . '/public/escape');
        symlink('../future.txt', $this->real . '/public/dangle');
        symlink($this->real, $this->link);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->base);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function expand(string $template): string
    {
        return strtr($template, ['{real}' => $this->real, '{link}' => $this->link]);
    }

    private function decide(
        PermissionMode $mode,
        string $pattern,
        PermissionAction $action,
        string $tool,
        string $path,
        ?string $root,
    ): PermissionDecision {
        $gate = new PermissionGate($mode, [new PermissionRule($this->expand($pattern), $action)]);

        return $gate->evaluate(new ToolCall($tool, ['file_path' => $this->expand($path)]), $root === null ? null : $this->expand($root));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function respellingsOfTheSecret(): iterable
    {
        yield 'bare relative' => ['secret.txt'];
        yield 'dot-slash' => ['./secret.txt'];
        yield 'via sub/..' => ['sub/../secret.txt'];
        yield 'doubled slashes and dots' => ['.//sub/./../secret.txt'];
        yield 'relative symlink' => ['notes'];
        yield 'absolute symlink' => ['{real}/notes'];
        yield 'through the symlinked root' => ['{link}/secret.txt'];
        yield 'symlink through the symlinked root' => ['{link}/notes'];
    }

    #[DataProvider('respellingsOfTheSecret')]
    public function testAbsoluteDenyCoversEveryRespellingWhenARootIsSupplied(string $spelling): void
    {
        $this->assertSame(
            PermissionDecision::Deny,
            $this->decide(PermissionMode::BypassPermissions, 'Read({real}/secret.txt)', PermissionAction::Deny, 'Read', $spelling, '{real}'),
        );
    }

    #[DataProvider('respellingsOfTheSecret')]
    public function testAskRulesGetTheSameUnion(string $spelling): void
    {
        // Backdrop is Default, not BypassPermissions: since the owner ruling of
        // 2026-10-06, bypass answers Ask-class rules with Allow, so it can no
        // longer isolate a rule-raised Ask. Default auto-allows a Read (its
        // evaluator grants read-only tools), so the Ask below can only have
        // come from the rule — the union the respellings must all reach.
        $this->assertSame(
            PermissionDecision::Ask,
            $this->decide(PermissionMode::Default, 'Read({real}/secret.txt)', PermissionAction::Ask, 'Read', $spelling, '{real}'),
        );
    }

    /**
     * The root as the USER spells it is a symlink: the deny is written under
     * that spelling, while realpath() reports the target.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function symlinkedRootSpellings(): iterable
    {
        yield 'pattern under link, relative call' => ['Read({link}/secret.txt)', 'secret.txt'];
        yield 'pattern under link, symlinked file' => ['Read({link}/secret.txt)', 'notes'];
        yield 'pattern under link, real absolute call' => ['Read({link}/secret.txt)', '{real}/secret.txt'];
        yield 'pattern under real, relative call' => ['Read({real}/secret.txt)', 'secret.txt'];
    }

    #[DataProvider('symlinkedRootSpellings')]
    public function testASymlinkedRootStillMatchesAPatternWrittenUnderEitherSpelling(string $pattern, string $spelling): void
    {
        $this->assertSame(
            PermissionDecision::Deny,
            $this->decide(PermissionMode::BypassPermissions, $pattern, PermissionAction::Deny, 'Read', $spelling, '{link}'),
        );
    }

    public function testARelativeDenyCatchesASymlinkToTheFileItNames(): void
    {
        $this->assertSame(
            PermissionDecision::Deny,
            $this->decide(PermissionMode::BypassPermissions, 'Read(secret.txt)', PermissionAction::Deny, 'Read', 'notes', '{real}'),
        );
    }

    public function testADirectorySymlinkIntoADeniedTreeIsCaughtEvenForANotYetExistingFile(): void
    {
        $this->assertSame(
            PermissionDecision::Deny,
            $this->decide(PermissionMode::BypassPermissions, 'Write({real}/secrets/*)', PermissionAction::Deny, 'Write', 'vault/new/deep.txt', '{real}'),
        );
    }

    public function testADanglingSymlinkIsJudgedByTheFileAWriteThroughItWouldCreate(): void
    {
        $this->assertSame(
            PermissionDecision::Deny,
            $this->decide(PermissionMode::BypassPermissions, 'Write({real}/future.txt)', PermissionAction::Deny, 'Write', 'ghost', '{real}'),
        );
    }

    /**
     * Null root is exactly the old, lexical-only matching: a caller with no
     * workspace (the sub-agent gate, a declaration check) decides as before.
     *
     * @return iterable<string, array{string, PermissionDecision}>
     */
    public static function rootlessDecisions(): iterable
    {
        yield 'absolute spelling still denied' => ['{real}/secret.txt', PermissionDecision::Deny];
        yield 'normalised absolute still denied' => ['{real}/sub/../secret.txt', PermissionDecision::Deny];
        yield 'relative spelling not anchored' => ['secret.txt', PermissionDecision::Allow];
        yield 'symlink not resolved' => ['{real}/notes', PermissionDecision::Allow];
    }

    #[DataProvider('rootlessDecisions')]
    public function testANullRootKeepsTheLexicalOnlyBehaviour(string $spelling, PermissionDecision $expected): void
    {
        $this->assertSame(
            $expected,
            $this->decide(PermissionMode::BypassPermissions, 'Read({real}/secret.txt)', PermissionAction::Deny, 'Read', $spelling, null),
        );
    }

    /**
     * An Allow fires only when the spelling AND the resolved file both match,
     * so the new spellings can narrow a grant but never launder one.
     *
     * @return iterable<string, array{string, string, PermissionDecision}>
     */
    public static function allowDecisions(): iterable
    {
        $abs = 'Write({real}/public/*)';
        $rel = 'Write(public/*)';

        yield 'absolute allow, relative call into the tree' => [$abs, 'public/ok.txt', PermissionDecision::Allow];
        yield 'absolute allow, absolute call into the tree' => [$abs, '{real}/public/ok.txt', PermissionDecision::Allow];
        yield 'absolute allow, new file in the tree' => [$abs, 'public/new.txt', PermissionDecision::Allow];
        yield 'absolute allow, call via the symlinked root' => [$abs, '{link}/public/ok.txt', PermissionDecision::Deny];
        yield 'absolute allow, symlink out of the tree (absolute)' => [$abs, '{real}/public/escape', PermissionDecision::Deny];
        yield 'absolute allow, symlink out of the tree (relative)' => [$abs, 'public/escape', PermissionDecision::Deny];
        yield 'absolute allow, dangling link out of the tree' => [$abs, '{real}/public/dangle', PermissionDecision::Deny];
        yield 'absolute allow, dot-dot out of the tree' => [$abs, 'public/../secret.txt', PermissionDecision::Deny];
        yield 'relative allow, plain call' => [$rel, 'public/ok.txt', PermissionDecision::Allow];
        yield 'relative allow, symlink out of the tree' => [$rel, 'public/escape', PermissionDecision::Deny];
        yield 'relative allow, no depth reading' => [$rel, 'sub/public/x.txt', PermissionDecision::Deny];
    }

    #[DataProvider('allowDecisions')]
    public function testAnAllowIsNeverWidenedByAResolvedSpelling(string $pattern, string $spelling, PermissionDecision $expected): void
    {
        $this->assertSame(
            $expected,
            $this->decide(PermissionMode::DontAsk, $pattern, PermissionAction::Allow, 'Write', $spelling, '{real}'),
        );
    }

    private function hookContext(string $path, string $root): HookContext
    {
        $args = ['file_path' => $path];

        return new HookContext(
            sessionId: 'test-session',
            toolName: 'Read',
            toolArgs: $args,
            toolInput: json_encode($args) ?: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $root,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hookSpellings(): iterable
    {
        yield 'relative' => ['secret.txt'];
        yield 'dot-slash' => ['./secret.txt'];
        yield 'symlink' => ['notes'];
    }

    #[DataProvider('hookSpellings')]
    public function testTheHookHandsTheContextRootToTheGate(string $spelling): void
    {
        $hook = new PermissionGateHook(new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('Read(' . $this->real . '/secret.txt)', PermissionAction::Deny)],
        ));

        $this->assertTrue($hook->execute($this->hookContext($spelling, $this->real))->isDenied());
    }

    public function testTheHookTreatsAnEmptyRootAsNoRoot(): void
    {
        $hook = new PermissionGateHook(new PermissionGate(
            PermissionMode::BypassPermissions,
            [new PermissionRule('Read(/secret.txt)', PermissionAction::Deny)],
        ));

        // Anchored at '' this would read as `/secret.txt` and be denied — a
        // file the tool, with no workspace, was never going to open.
        $this->assertTrue($hook->execute($this->hookContext('secret.txt', ''))->isAllowed());
    }
}

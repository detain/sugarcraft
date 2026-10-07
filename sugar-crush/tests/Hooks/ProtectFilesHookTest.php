<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * @see ProtectFilesHook
 */
final class ProtectFilesHookTest extends TestCase
{
    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame('protect-files', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame('^(Bash|Edit|Write|ApplyPatch|Read|Grep|Glob|Lsp|mcp__.*)$', $hook->matcher());
    }

    // =========================================================================
    // Protected File Denial Tests
    // =========================================================================

    public function testDenyEnvFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Bash', 'echo $HOME && nano .env');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('.env', $result->message);
    }

    /**
     * Composer manifests are neither secrets nor policy, so no default pattern
     * may refuse them — the session this regresses was an audit whose very
     * first `[ -f "$d/composer.json" ]` probe came back "Hook denied".
     */
    public function testComposerManifestsAreNotProtectedByDefault(): void
    {
        $hook = new ProtectFilesHook();

        foreach (['composer.json', 'composer.lock', 'candy-core/composer.json'] as $path) {
            foreach (['Read', 'Edit', 'Write'] as $tool) {
                $this->assertTrue($hook->execute($this->createContext($tool, $path))->isAllowed(), "$tool $path");
            }
        }

        $this->assertTrue($hook->execute($this->createContext('Bash', 'grep -c . candy-core/composer.json'))->isAllowed());
        $this->assertTrue($hook->execute($this->createContext(
            'Bash',
            'for d in candy-*; do [ -f "$d/composer.json" ] || echo "$d NO-MANIFEST"; done',
        ))->isAllowed());
    }

    public function testDenyGitConfig(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', '.git/config');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('.git', $result->message);
    }

    public function testDenyConfigPhpFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'config/app.php');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('config\\/', $result->message);
    }

    // =========================================================================
    // The files that decide what this session may do
    // =========================================================================

    /**
     * `trustedProjectHooks` lives in `~/.sugar-crush/config.json`, and the
     * shipped default permission mode is bypass-permissions — so without this
     * deny the model could grant itself the trust the gate exists to withhold,
     * unprompted, and a provider switch away from running a cloned
     * repository's shell.
     *
     * @dataProvider policyFileCalls
     */
    public function testAPolicyFileMayNotBeWrittenByTheSession(string $tool, string $input): void
    {
        $hook = new ProtectFilesHook();

        $this->assertTrue(
            $hook->execute($this->createContext($tool, $input))->isDenied(),
            "{$tool} {$input} must not reach the trust gate's own config",
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function policyFileCalls(): array
    {
        return [
            'the trust list itself' => ['Write', '/home/someone/.sugar-crush/config.json'],
            'the ungated user hook file' => ['Write', '/home/someone/.sugar-crush/hooks.yaml'],
            'a project hook file' => ['Edit', '/w/repo/.sugar-crush/hooks.yaml'],
            'an agent preset (permissionMode + tools)' => ['Write', '/w/repo/.sugar-crush/agents/free.md'],
            'the obvious shell spelling' => ['Bash', "echo '{}' >> ~/.sugar-crush/config.json"],
            'a shell append to the hook file' => ['Bash', 'cat payload >> /w/repo/.sugar-crush/hooks.yaml'],
        ];
    }

    /**
     * READING A POLICY FILE GRANTS NO CAPABILITY, so the deny is write-side
     * only. These are PROJECT-scoped spellings — the pattern is unanchored, so
     * it catches them as readily as the `~/`-scoped file the trust list
     * actually lives in, and the first two are real files in this checkout when
     * the suite runs from `sugar-crush/`. Denying `Read` on
     * them blocked ordinary inspection ("why is my reviewer preset behaving
     * oddly") and bought no containment, because a decision is changed by
     * WRITING it. Contrast `.env` below, a SECRET, where refusing the read IS
     * the point.
     *
     * @dataProvider policyFilesInThisRepo
     */
    public function testAPolicyFileMayBeReadBackButNotWritten(string $path): void
    {
        $hook = new ProtectFilesHook();

        $this->assertTrue(
            $hook->execute($this->createContext('Read', $path))->isAllowed(),
            "Read {$path} decides nothing and must not be refused",
        );
        $this->assertTrue(
            $hook->execute($this->createContext('Write', $path))->isDenied(),
            "Write {$path} is the session editing its own policy",
        );
        $this->assertTrue(
            $hook->execute($this->createContext('Edit', $path))->isDenied(),
            "Edit {$path} is the session editing its own policy",
        );
    }

    /** @return array<string, array{0: string}> */
    public static function policyFilesInThisRepo(): array
    {
        return [
            'an agent preset' => ['.sugar-crush/agents/coder.md'],
            'the project config' => ['.sugar-crush/config.json'],
            'a project hook file' => ['.sugar-crush/hooks.yaml'],
        ];
    }

    /**
     * The carve-out above is scoped to the POLICY patterns. A secret stays
     * unreadable, which is what makes the two halves of the default list
     * genuinely different rather than one rule with an exception.
     */
    public function testASecretIsStillUnreadable(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertTrue($hook->execute($this->createContext('Read', '/w/repo/.env'))->isDenied());
    }

    /**
     * `Bash` gets the full list on both sides: one shell string does not say
     * whether it is about to read the file or rewrite it.
     */
    public function testAShellCommandNamingThePolicyFileIsDeniedEvenWhenItLooksLikeARead(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertTrue($hook->execute($this->createContext('Bash', 'cat ~/.sugar-crush/config.json'))->isDenied());
    }

    /**
     * A pattern matches TEXT; a write touches an INODE. `ln -s
     * ~/.sugar-crush/config.json ./notes.json` makes those two different
     * strings for one file, so the path is canonicalised before matching.
     */
    public function testASymlinkPointingAtThePolicyFileIsStillThePolicyFile(): void
    {
        $dir = sys_get_temp_dir() . '/protect_files_' . uniqid('', true);
        mkdir($dir . '/.sugar-crush', 0700, true);
        file_put_contents($dir . '/.sugar-crush/config.json', '{}');

        if (!@symlink($dir . '/.sugar-crush/config.json', $dir . '/notes.json')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        try {
            $hook = new ProtectFilesHook();

            $this->assertTrue($hook->execute($this->createContext('Write', $dir . '/notes.json'))->isDenied());
        } finally {
            @unlink($dir . '/notes.json');
            @unlink($dir . '/.sugar-crush/config.json');
            @rmdir($dir . '/.sugar-crush');
            @rmdir($dir);
        }
    }

    /**
     * The file `Write` is about usually does not exist yet, so the PARENT is
     * what gets canonicalised — which is also what catches a link pointing at
     * the config DIRECTORY rather than at a file in it.
     */
    public function testASymlinkedConfigDirectoryIsStillTheConfigDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/protect_files_' . uniqid('', true);
        mkdir($dir . '/.sugar-crush', 0700, true);

        if (!@symlink($dir . '/.sugar-crush', $dir . '/alias')) {
            $this->markTestSkipped('this filesystem does not support symlinks');
        }

        try {
            $hook = new ProtectFilesHook();

            // hooks.yaml does not exist: this is the create case.
            $this->assertTrue($hook->execute($this->createContext('Write', $dir . '/alias/hooks.yaml'))->isDenied());
        } finally {
            @unlink($dir . '/alias');
            @rmdir($dir . '/.sugar-crush');
            @rmdir($dir);
        }
    }

    /**
     * The guard names two files exactly, not a prefix of them: this repo's own
     * checked-in `.sugar-crush/config.dev.json` fixture is neither.
     */
    public function testASiblingThatMerelySharesThePrefixIsNotProtected(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertTrue($hook->execute($this->createContext('Edit', '/w/repo/.sugar-crush/config.dev.json'))->isAllowed());
        $this->assertTrue($hook->execute($this->createContext('Edit', '/w/repo/.sugar-crush/hooks.yaml.dist'))->isAllowed());
    }

    // =========================================================================
    // Non-Protected File Allow Tests
    // =========================================================================

    public function testAllowOther(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', 'echo "hello" > /tmp/test.txt');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowSrcPhpFile(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'src/MyClass.php');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowReadme(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', 'cat README.md');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Execute Method Reads ToolInput From Context
    // =========================================================================

    public function testExecuteReadsToolInput(): void
    {
        $hook = new ProtectFilesHook();
        // Same command but different toolInput - the protection should trigger
        $context = $this->createContext('bash', 'nano .env');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testExecuteUsesContextToolInput(): void
    {
        $hook = new ProtectFilesHook();
        // With toolInput that doesn't match any protected pattern
        $context = $this->createContext('bash', 'ls -la');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Edge Case Tests
    // =========================================================================

    public function testAllowEmptyInput(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('bash', '');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
    }

    /**
     * `.env` glued to a word is not a `.env`. This test used to pin
     * `ls /path/to/.env.backup` as ALLOWED — but a backup of the secret is the
     * secret, and that "partial match" was the same boundary that let
     * `cat .env;true` through (audit F-J2). What genuinely is a different name
     * stays allowed, or `grep -rn process.env src` would be refused.
     */
    public function testPartialPathMatchDoesNotTrigger(): void
    {
        $hook = new ProtectFilesHook();

        foreach ([
            'grep -rn process.env.API_KEY src',
            'cat foo.env.example',
            'php dotenv.php',
            'cat .environment',
            'ls .env/bin',
            'cp .env.example .env.dist',
        ] as $command) {
            $this->assertTrue($hook->execute($this->createContext('bash', $command))->isAllowed(), $command);
        }

        $this->assertTrue($hook->execute($this->createContext('bash', 'ls /path/to/.env.backup'))->isDenied());
    }

    // =========================================================================
    // Audit F-J2 — every spelling, every reading tool, through the real chain
    // =========================================================================

    /**
     * The full built-in chain, as the CLI registers it, so a verdict here is
     * the verdict a session gets. Each DENY row passed the chain before the
     * fix (the matcher never saw Grep/Glob/Lsp/MCP, and the `.env` regex wanted
     * whitespace on both sides of the name); `Read .env` and `cat .env` are the
     * two that were already refused, kept as the control.
     *
     * @param array<string, mixed> $args
     * @dataProvider secretFileCalls
     */
    public function testTheChainRefusesEverySpellingOfASecretFile(string $tool, array $args): void
    {
        $this->assertTrue(
            $this->chainVerdict($tool, $args)->isDenied(),
            "{$tool} " . json_encode($args) . ' must not reach a secret file',
        );
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function secretFileCalls(): array
    {
        return [
            'Read .env (control)' => ['Read', ['file_path' => '.env']],
            'cat .env (control)' => ['Bash', ['command' => 'cat .env']],
            'separator after the name' => ['Bash', ['command' => 'cat .env;true']],
            'pipe after the name' => ['Bash', ['command' => 'cat .env|x']],
            'double-quoted' => ['Bash', ['command' => 'cat ".env"']],
            'single-quoted' => ['Bash', ['command' => "cat '.env'"]],
            'quotes inside the name' => ['Bash', ['command' => "cat .e''nv"]],
            'input redirection' => ['Bash', ['command' => 'cat <.env']],
            'dot-slash' => ['Bash', ['command' => 'cat ./.env']],
            '.env.local' => ['Bash', ['command' => 'cat .env.local']],
            '.env.production' => ['Read', ['file_path' => 'app/.env.production']],
            'direnv .envrc' => ['Bash', ['command' => 'cat .envrc']],
            'env-file flag' => ['Bash', ['command' => 'docker run --env-file=.env img']],
            '.git/config' => ['Bash', ['command' => 'cat .git/config']],
            '.git/config double-quoted' => ['Bash', ['command' => 'cat ".git/config"']],
            '.git/config quote inside' => ['Bash', ['command' => "cat .git/'config'"]],
            '.git/config redirected in' => ['Bash', ['command' => 'cat < .git/"config"']],
            'Grep path=.env' => ['Grep', ['pattern' => '=', 'path' => '.env']],
            'Grep include=.env.local' => ['Grep', ['pattern' => '=', 'path' => '.', 'include' => '.env.local']],
            'lowercase grep' => ['grep', ['pattern' => '=', 'path' => '.env']],
            'Glob for every .env' => ['Glob', ['pattern' => '**/.env*', 'path' => '.']],
            'Lsp on .env' => ['Lsp', ['operation' => 'hover', 'path' => '.env']],
            'MCP read' => ['mcp__fs__read_file', ['path' => '.env']],
            'MCP nested list' => ['mcp__fs__read_multiple', ['paths' => ['src/a.php', '.git/config']]],
            'MCP shell line' => ['mcp__shell__run', ['command' => 'cat ".env";true']],
            'MCP write to policy' => ['mcp__fs__write_file', ['path' => '.sugar-crush/hooks.yaml', 'content' => 'x']],
            // Step 0.14-c: key material is read-denied like `.env`.
            'Read a TLS key' => ['Read', ['file_path' => 'certs/server.key']],
            'cat a pem, any case' => ['Bash', ['command' => 'cat deploy/signing.PEM']],
            'Grep include *.pem' => ['Grep', ['pattern' => 'BEGIN', 'path' => '.', 'include' => '*.pem']],
            'Glob for every key' => ['Glob', ['pattern' => '**/*.key', 'path' => '.']],
            'Read an ssh identity' => ['Read', ['file_path' => '/home/u/.ssh/id_ed25519']],
            'cat id_rsa quoted' => ['Bash', ['command' => 'cat "$HOME/.ssh/id_rsa"']],
            'a suffixed identity' => ['Bash', ['command' => 'cat ~/.ssh/id_rsa_work']],
            'a security-key identity' => ['Read', ['file_path' => '.ssh/id_ecdsa_sk']],
            'MCP read of a key' => ['mcp__fs__read_file', ['path' => 'tls/private.key']],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @dataProvider ordinaryCalls
     */
    public function testTheChainStillAllowsOrdinaryWork(string $tool, array $args): void
    {
        $this->assertTrue(
            $this->chainVerdict($tool, $args)->isAllowed(),
            "{$tool} " . json_encode($args) . ' is ordinary work and must not be refused',
        );
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function ordinaryCalls(): array
    {
        return [
            'cat README.md' => ['Bash', ['command' => 'cat README.md']],
            'the committed template' => ['Bash', ['command' => 'cat .env.example']],
            'a staged template' => ['Read', ['file_path' => '.env.production.sample']],
            'grep for env in src' => ['Bash', ['command' => 'grep -rn env src']],
            'Grep the tree for env' => ['Grep', ['pattern' => 'env', 'path' => '.']],
            'Grep for the TEXT .env' => ['Grep', ['pattern' => '.env', 'path' => 'src']],
            'Glob php' => ['Glob', ['pattern' => '**/*.php', 'path' => '.']],
            'Glob the templates' => ['Glob', ['pattern' => '**/.env.example', 'path' => '.']],
            'Lsp on source' => ['Lsp', ['operation' => 'hover', 'path' => 'src/a.php']],
            'MCP read of source' => ['mcp__fs__read_file', ['path' => 'src/a.php']],
            'Grep may read a policy file' => ['Grep', ['pattern' => 'tools', 'path' => '.sugar-crush/agents']],
            'WebFetch is not judged' => ['WebFetch', ['url' => 'https://example.com/docs/.env']],
            // Step 0.14-c's boundaries: the public half and the lookalikes.
            'the public ssh key' => ['Read', ['file_path' => '/home/u/.ssh/id_ed25519.pub']],
            'cat id_rsa.pub' => ['Bash', ['command' => 'cat ~/.ssh/id_rsa.pub']],
            'jq property .key' => ['Bash', ['command' => "jq '.key' data.json"]],
            'a .keys file is another name' => ['Read', ['file_path' => 'config/server.keys']],
            'keyboard source' => ['Read', ['file_path' => 'src/keyboard.php']],
            'Grep for the TEXT .pem' => ['Grep', ['pattern' => 'cert.pem', 'path' => 'src']],
        ];
    }

    /**
     * `json_encode()` writes `/` as `\/`, so the old fallback — matching the
     * raw JSON `toolInput` — never saw `.git/config` in any non-Bash tool. The
     * decoded leaves are what get matched now; this pins it at the hook level,
     * where toolInput is exactly the escaped JSON a real call carries.
     */
    public function testAnMcpPathIsMatchedDecodedNotAsEscapedJson(): void
    {
        $args = ['uri' => 'file:///w/repo/.git/config'];
        $this->assertStringContainsString('\\/', (string) json_encode($args));

        $this->assertTrue((new ProtectFilesHook())->execute(new HookContext(
            sessionId: 's',
            toolName: 'mcp__fs__read',
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'm',
            provider: 'p',
            projectRoot: '/w/repo',
        ))->isDenied());
    }

    // =========================================================================
    // Audit F-J4 — .git/hooks and .git/info are written in no mode
    // =========================================================================

    /**
     * A file in `.git/hooks/` runs on the user's next `git commit`, in their
     * own shell and outside every sugar-crush mode — so the deny has to come
     * from this hook, ahead of the gate, and hold in `bypass-permissions` (the
     * shipped default) as much as anywhere. Each row passed the full chain
     * under bypass before the fix; the gate is registered LAST with the given
     * mode, exactly as `Bootstrap::hooks()` orders it, and the message is
     * asserted so a mode that would have refused anyway (plan) cannot make the
     * row pass for the wrong reason.
     *
     * @param array<string, mixed> $args
     * @dataProvider gitMachineryWritesInEveryMode
     */
    public function testGitMachineryIsNotWrittenInAnyMode(PermissionMode $mode, string $tool, array $args): void
    {
        $verdict = $this->chainVerdict($tool, $args, $mode);

        $this->assertTrue(
            $verdict->isDenied(),
            "{$mode->value}: {$tool} " . json_encode($args) . ' must not write git machinery',
        );
        $this->assertStringContainsString('prevents modification of files matching', (string) $verdict->message);
    }

    /** @return array<string, array{0: PermissionMode, 1: string, 2: array<string, mixed>}> */
    public static function gitMachineryWritesInEveryMode(): array
    {
        $calls = [
            'Write a pre-commit hook' => ['Write', ['file_path' => '.git/hooks/pre-commit', 'content' => "#!/bin/sh\n"]],
            'Edit info/attributes' => ['Edit', ['file_path' => '.git/info/attributes', 'old_string' => 'a', 'new_string' => 'b']],
            'Bash cp, quoted target' => ['Bash', ['command' => 'cp x ".git/hooks/pre-commit"']],
            'Bash cp, dot-slash' => ['Bash', ['command' => 'cp ./payload.sh ./.git/hooks/pre-commit']],
            'Bash quote inside the name' => ['Bash', ['command' => "cp x .git/'hooks'/pre-push"]],
            'Bash doubled slash' => ['Bash', ['command' => 'cp x .git//hooks/pre-commit']],
            'MCP write' => ['mcp__fs__write_file', ['path' => '.git/hooks/post-checkout', 'content' => 'x']],
        ];

        $rows = [];
        foreach (PermissionMode::cases() as $mode) {
            foreach ($calls as $label => [$tool, $args]) {
                $rows["{$mode->value}: {$label}"] = [$mode, $tool, $args];
            }
        }

        return $rows;
    }

    /**
     * The bypass contract after the owner ruling of 2026-10-06. Three things
     * hold at once through the real chain:
     *
     *  - the POLICY FLOOR still denies — writing `hooks.yaml`, `config.json`,
     *    or an `agents/` preset is how a session turns any mode's word into
     *    code, so bypass, which the human chose for themselves, does not let
     *    the model choose it for them. The message is asserted so the row
     *    cannot pass via some later layer.
     *  - the FILE-CLASS protections flip to allow (`.env`, key material) —
     *    bypass means the human already answered "yes" to exactly these.
     *  - reads of the policy surface and write-lookalikes were never this
     *    hook's, and stay allowed, as does everything under a plain gate.
     *
     * The `POLICY_ASK` class is deliberately NOT waived: `.mcp.json` and the
     * skills/commands surfaces still ask, and headless they fail closed like
     * every other ask this file pins.
     */
    public function testBypassKeepsThePolicyFloorAndFlipsTheRest(): void
    {
        $mode = PermissionMode::BypassPermissions;

        // Reads and lookalikes — as before the ruling.
        $this->assertTrue($this->chainVerdict('Read', ['file_path' => '.git/hooks/pre-commit'], $mode)->isAllowed());
        $this->assertTrue($this->chainVerdict('Read', ['file_path' => '.git/info/exclude'], $mode)->isAllowed());
        $this->assertTrue(
            $this->chainVerdict('Write', ['file_path' => '.github/workflows/ci.yml', 'content' => 'x'], $mode)->isAllowed(),
        );
        $this->assertTrue(
            $this->chainVerdict('Write', ['file_path' => '.git/hooks-sample/README', 'content' => 'x'], $mode)->isAllowed(),
        );

        // The floor: every WRITE_ONLY pattern denies in bypass, message named,
        // so a pass could only come from this hook's list — not the mode.
        $floor = [
            'hooks.yaml' => ['Write', ['file_path' => '.sugar-crush/hooks.yaml', 'content' => 'x']],
            'config.json' => ['Write', ['file_path' => '.sugar-crush/config.json', 'content' => '{}']],
            'agents preset' => ['Write', ['file_path' => '.sugar-crush/agents/extra.md', 'content' => 'x']],
            'git hook' => ['Write', ['file_path' => '.git/hooks/pre-commit', 'content' => "#!/bin/sh\n"]],
            'bash spelling' => ['Bash', ['command' => 'cp payload.sh .sugar-crush/hooks.yaml']],
        ];
        foreach ($floor as $label => [$tool, $args]) {
            $verdict = $this->chainVerdict($tool, $args, $mode);
            $this->assertTrue($verdict->isDenied(), "the policy floor must hold in bypass: {$label}");
            $this->assertStringContainsString('prevents modification of files matching', (string) $verdict->message);
        }

        // File class flips: a secret is a prompt the human waived, not a wall.
        $this->assertTrue($this->chainVerdict('Edit', ['file_path' => '.env', 'old_string' => 'a', 'new_string' => 'b'], $mode)->isAllowed());
        $this->assertTrue($this->chainVerdict('Read', ['file_path' => '.env'], $mode)->isAllowed());
        $this->assertTrue($this->chainVerdict('Read', ['file_path' => '/home/u/.ssh/id_rsa'], $mode)->isAllowed());

        // Control: outside bypass the file class is a wall again.
        $this->assertTrue(
            $this->chainVerdict('Edit', ['file_path' => '.env', 'old_string' => 'a', 'new_string' => 'b'], PermissionMode::Default)->isDenied(),
        );

        // POLICY_ASK is not waived: bypass still puts the question.
        $asked = $this->chainVerdict('Write', ['file_path' => '.mcp.json', 'content' => '{}'], $mode);
        $this->assertTrue($asked->isAsk(), 'the policy-ask class answers to the human, not to the mode');
    }

    // =========================================================================
    // Configurable Protected-Files List
    // =========================================================================

    public function testDefaultsUsedWhenNotConfigured(): void
    {
        $hook = new ProtectFilesHook();

        $this->assertSame(
            ProtectFilesHook::DEFAULT_PROTECTED_PATTERNS,
            $hook->protectedPatterns()
        );
    }

    public function testCustomProtectedListIsHonored(): void
    {
        $hook = new ProtectFilesHook(['/secrets\.yaml\b/']);

        // The custom pattern denies its target...
        $this->assertTrue($hook->execute($this->createContext('Edit', 'secrets.yaml'))->isDenied());
        // ...and the built-in defaults are NOT applied (only the custom list is).
        $this->assertTrue($hook->execute($this->createContext('Edit', '.git/config'))->isAllowed());
    }

    public function testWithProtectedPatternsIsImmutable(): void
    {
        $hook = new ProtectFilesHook();
        $custom = $hook->withProtectedPatterns(['/secrets\.yaml\b/']);

        // Original instance keeps the defaults (still denies .git/config).
        $this->assertTrue($hook->execute($this->createContext('Edit', '.git/config'))->isDenied());
        // New instance guards only the custom pattern.
        $this->assertTrue($custom->execute($this->createContext('Edit', 'secrets.yaml'))->isDenied());
        $this->assertTrue($custom->execute($this->createContext('Edit', '.git/config'))->isAllowed());
        $this->assertNotSame($hook, $custom);
    }

    public function testEmptyProtectedListProtectsNothing(): void
    {
        $hook = new ProtectFilesHook([]);

        $this->assertTrue($hook->execute($this->createContext('Edit', '.git/config'))->isAllowed());
        $this->assertTrue($hook->execute($this->createContext('Bash', 'nano .env'))->isAllowed());
    }

    public function testNullConstructorArgKeepsDefaults(): void
    {
        $hook = new ProtectFilesHook(null);

        $this->assertSame(
            ProtectFilesHook::DEFAULT_PROTECTED_PATTERNS,
            $hook->protectedPatterns()
        );
        $this->assertTrue($hook->execute($this->createContext('Edit', '.git/config'))->isDenied());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * The built-in chain; with $mode, the permission gate is registered last,
     * as `Bootstrap::hooks()` does.
     *
     * @param array<string, mixed> $args
     */
    private function chainVerdict(string $tool, array $args, ?PermissionMode $mode = null): HookResult
    {
        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        if ($mode !== null) {
            $manager->register(new PermissionGateHook(new PermissionGate($mode)));
        }

        return $manager->preToolUse(new HookContext(
            sessionId: 'test-session-123',
            toolName: $tool,
            toolArgs: $args,
            toolInput: (string) json_encode($args),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        ));
    }

    private function createContext(string $toolName, string $toolInput): HookContext
    {
        $normalizedToolName = ucfirst(strtolower($toolName));
        $toolArgs = match ($normalizedToolName) {
            'Bash' => ['command' => $toolInput],
            'Edit', 'Write', 'Read' => ['file_path' => $toolInput],
            default => [],
        };

        return new HookContext(
            sessionId: 'test-session-123',
            toolName: $toolName,
            toolArgs: $toolArgs,
            toolInput: json_encode($toolArgs),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: '/tmp/test-project',
        );
    }
}

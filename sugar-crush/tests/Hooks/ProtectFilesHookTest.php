<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;

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

        $this->assertSame('^(Bash|Edit|Write|Read)$', $hook->matcher());
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

    public function testDenyComposerJson(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'composer.json');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
    }

    public function testDenyComposerLock(): void
    {
        $hook = new ProtectFilesHook();
        $context = $this->createContext('Edit', 'composer.lock');

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
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

    public function testPartialPathMatchDoesNotTrigger(): void
    {
        $hook = new ProtectFilesHook();
        // Should NOT match .env in middle of path, only exact .env at end
        $context = $this->createContext('bash', 'ls /path/to/.env.backup');

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
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
        $this->assertTrue($hook->execute($this->createContext('Edit', 'composer.json'))->isAllowed());
    }

    public function testWithProtectedPatternsIsImmutable(): void
    {
        $hook = new ProtectFilesHook();
        $custom = $hook->withProtectedPatterns(['/secrets\.yaml\b/']);

        // Original instance keeps the defaults (still denies composer.json).
        $this->assertTrue($hook->execute($this->createContext('Edit', 'composer.json'))->isDenied());
        // New instance guards only the custom pattern.
        $this->assertTrue($custom->execute($this->createContext('Edit', 'secrets.yaml'))->isDenied());
        $this->assertTrue($custom->execute($this->createContext('Edit', 'composer.json'))->isAllowed());
        $this->assertNotSame($hook, $custom);
    }

    public function testEmptyProtectedListProtectsNothing(): void
    {
        $hook = new ProtectFilesHook([]);

        $this->assertTrue($hook->execute($this->createContext('Edit', 'composer.json'))->isAllowed());
        $this->assertTrue($hook->execute($this->createContext('Bash', 'nano .env'))->isAllowed());
    }

    public function testNullConstructorArgKeepsDefaults(): void
    {
        $hook = new ProtectFilesHook(null);

        $this->assertSame(
            ProtectFilesHook::DEFAULT_PROTECTED_PATTERNS,
            $hook->protectedPatterns()
        );
        $this->assertTrue($hook->execute($this->createContext('Edit', 'composer.json'))->isDenied());
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

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

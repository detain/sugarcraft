<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\BuiltIn\BashEscapeDenyHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;

/**
 * @see BashEscapeDenyHook
 */
final class BashEscapeDenyHookTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/bash_escape_deny_' . uniqid('', true);
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    // =========================================================================
    // Basic Interface Tests
    // =========================================================================

    public function testName(): void
    {
        $this->assertSame('bash-escape-deny', (new BashEscapeDenyHook($this->root))->name());
    }

    public function testEvent(): void
    {
        $this->assertSame(HookEvent::PreToolUse, (new BashEscapeDenyHook($this->root))->event());
    }

    public function testMatcher(): void
    {
        $this->assertSame('^Bash$', (new BashEscapeDenyHook($this->root))->matcher());
    }

    // =========================================================================
    // Escaping-path Denial Tests
    // =========================================================================

    public function testDeniesAbsolutePathOutsideRoot(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat /etc/passwd'));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('/etc/passwd', $result->message);
    }

    public function testDeniesRelativeDotDotEscape(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat ../../etc/passwd'));

        $this->assertTrue($result->isDenied());
    }

    public function testDeniesRedirectionOutsideRoot(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('echo pwned > /etc/cron.d/x'));

        $this->assertTrue($result->isDenied());
    }

    // =========================================================================
    // In-jail Allow Tests
    // =========================================================================

    public function testAllowsRelativeInJailPath(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat notes.txt'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsAbsoluteInJailPath(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('cat ' . $this->root . '/notes.txt'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsCommandWithNoPaths(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context('ls -la'));

        $this->assertTrue($result->isAllowed());
    }

    public function testAllowsEmptyCommand(): void
    {
        $hook = new BashEscapeDenyHook($this->root);

        $result = $hook->execute($this->context(''));

        $this->assertTrue($result->isAllowed());
    }

    // =========================================================================
    // Audit F-J5: the r14_escape.php table, plus the same-kind spellings
    // =========================================================================

    /**
     * `{root}` is the jail root, `{home}` a home directory OUTSIDE it.
     *
     * @return array<string, array{string, bool}> command => expected denial
     */
    public static function escapeTable(): array
    {
        return [
            // r14_escape.php, verbatim: every row was the wrong way round.
            'redirect to /dev/null is inert' => ['composer test > /dev/null 2>&1', false],
            'absolute executable in command position' => ['/bin/sh -c true', false],
            'tilde home' => ['cat ~/.ssh/id_rsa', true],
            '$HOME' => ['cat $HOME/.aws/credentials', true],
            'redirection glued to the command' => ['cat</etc/shadow', true],
            'dot-dot glued to a separator' => ['cd ..;ls', true],
            'output redirection glued to its target' => ['echo x >/tmp/out', true],
            '$OLDPWD' => ['cp x "$OLDPWD"', true],

            // Expansion spellings.
            '${HOME}' => ['cat "${HOME}"/.netrc', true],
            'bare tilde' => ['ls ~', true],
            'another user\'s home' => ['cat ~root/.bashrc', true],
            'redirect into home' => ['echo x > ~/.bashrc', true],
            '$PWD stays in the root' => ['cat "$PWD/notes.txt"', false],
            '$PWD then dot-dot' => ['cat $PWD/../secret', true],
            'single-quoted $HOME is literal and relative' => ["echo '\$HOME'", false],
            'cd - returns to $OLDPWD' => ['cd - && cat x', true],
            'unknown variable then dot-dot' => ['cat $X/../../y', true],

            // Separators, pipes, subshells.
            'after a pipe' => ['ls | tee /tmp/list', true],
            'inside a subshell' => ['(cd /; ls)', true],
            'after &&' => ['true&&cat /etc/hosts', true],

            // Nested command text.
            'command substitution body' => ['echo $(cat /etc/shadow)', true],
            'backtick body' => ['echo `cat /etc/shadow`', true],
            'process substitution body' => ['diff <(cat /etc/hosts) notes.txt', true],
            'sh -c script' => ["sh -c 'cat /etc/shadow'", true],
            'bash -lc script' => ['bash -lc "cat ~/.ssh/id_rsa"', true],
            'eval' => ['eval cat /etc/shadow', true],
            'assignment from a substitution' => ['k=$(cat ~/.aws/credentials)', true],
            'brace alternative' => ['cat {/etc/shadow,}', true],
            'option value' => ['sort --output=/tmp/x notes.txt', true],
            'substitution of an in-root path' => ['cat $(pwd)/notes.txt', false],

            // Harmless devices and fd plumbing.
            'stderr to stdout' => ['make 2>&1', false],
            'read /dev/urandom' => ['head -c 16 /dev/urandom', false],
            'redirect to /dev/stderr' => ['echo oops >&2', false],
            'here-doc delimiter' => ["cat <<EOF\nhello\nEOF", false],
            'env-wrapped executable' => ['env FOO=1 /bin/sh -c true', false],
            'non-executable absolute path in command position' => ['/etc/passwd', true],

            // In-root work that must stay allowed.
            'relative path' => ['cat src/App.php', false],
            'absolute path inside the root' => ['cat {root}/notes.txt', false],
            'dot-dot that stays inside' => ['cat src/../notes.txt', false],
            'quoted operator is not a separator' => ['echo "a;cd .."', false],
            'flag only' => ['ls -la', false],
        ];
    }

    #[DataProvider('escapeTable')]
    public function testEscapeTable(string $command, bool $denied): void
    {
        $hook = new BashEscapeDenyHook($this->root, '/home/escape-test-user');
        $command = str_replace('{root}', $this->root, $command);

        $result = $hook->execute($this->context($command));

        $this->assertSame(
            $denied,
            $result->isDenied(),
            ($denied ? 'should deny: ' : 'should allow: ') . $command . ' — ' . $result->message,
        );
    }

    public function testTildeInsideTheRootIsAllowedWhenHomeContainsIt(): void
    {
        $hook = new BashEscapeDenyHook($this->root, dirname($this->root));

        $result = $hook->execute($this->context('cat ~/' . basename($this->root) . '/notes.txt'));

        $this->assertTrue($result->isAllowed(), $result->message);
    }

    public function testAnUnknownHomeDeniesRatherThanExpandingToNothing(): void
    {
        // '' would turn `$HOME/x` into `/x`; a relative home is not a home.
        $hook = new BashEscapeDenyHook($this->root, 'relative/home');

        $this->assertTrue($hook->execute($this->context('cat $HOME/notes.txt'))->isDenied());
        $this->assertTrue($hook->execute($this->context('cat ~/notes.txt'))->isDenied());
    }

    public function testAnUnterminatedQuoteStillHasItsRawPathsJudged(): void
    {
        $hook = new BashEscapeDenyHook($this->root, '/home/escape-test-user');

        $this->assertTrue($hook->execute($this->context('cat /etc/shadow "unterminated'))->isDenied());
    }

    public function testTheDenialNamesTheOffendingWord(): void
    {
        $hook = new BashEscapeDenyHook($this->root, '/home/escape-test-user');

        $result = $hook->execute($this->context('ls; cat</etc/shadow'));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('/etc/shadow', $result->message);
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function context(string $command): HookContext
    {
        return new HookContext(
            sessionId: 'test-session',
            toolName: 'Bash',
            toolArgs: ['command' => $command],
            toolInput: json_encode(['command' => $command]),
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->root,
        );
    }
}

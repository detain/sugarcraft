<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\ShareHostCommand;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Share\ShareStore;

/**
 * SES-3-P3: the `/share` host command wraps `ShareCommand::execute()` in a
 * try/finally around its output buffer, and the command itself refuses a
 * database it cannot open instead of throwing through that buffer.
 *
 * Before the fix an unopenable database escaped `run()` with the capture
 * buffer still open — every later host write on the connection poured into
 * a buffer nobody would ever clean. The revoke-with-token shape is the
 * discriminating regression: pre-fix it THROWS (test red), post-fix it
 * answers a failure {@see \SugarCraft\Code\Host\Commands\CommandResult}
 * with `ob_get_level()` untouched.
 *
 * The "read-only database" fixture is a file chmod'ed 0444 after the store
 * that created it is built; the fresh connect inside `ShareStore::atPath()`
 * is what fails, and it fails for any non-root uid. candy-code's own
 * RootRunGuard refuses the suite under uid 0, so the fixture bites wherever
 * these tests run.
 */
final class ShareHostCommandBufferTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-ses3b-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @chmod($file, 0600);
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testRevokeOnAReadOnlyDatabaseFailsAsAResultWithoutLeakingTheBuffer(): void
    {
        $context = $this->contextWithReadOnlyDatabase($token);

        $level = ob_get_level();
        $result = (new ShareHostCommand())->run($context, '/share revoke ' . $token);

        $this->assertSame($level, ob_get_level(), 'the capture buffer is closed on the failure path');
        $this->assertCount(2, $result->rows, 'the echo, then the refusal');
        $this->assertSame(Role::System, $result->rows[1]->role, 'a failed command is a notice, not a model reply');
        $this->assertNotSame('', trim($result->rows[1]->content), 'and the notice says why');
    }

    /**
     * `/share list` reads through the SAME store seam as every other verb,
     * so a context with no session database answers the same refusal shape
     * (a read-only database is not one of these: listing is a read, and
     * reads still work there — only the write paths refuse).
     */
    public function testListWithoutAnyStoreRefusesTheSameWay(): void
    {
        $context = CommandContext::new(
            history: [Message::user('hi'), Message::assistant('hello')],
            sessionId: 'home',
            root: $this->dir,
        );

        $level = ob_get_level();
        $result = (new ShareHostCommand())->run($context, '/share list');

        $this->assertSame($level, ob_get_level());
        $this->assertSame(Role::System, $result->rows[1]->role);
    }

    /**
     * The happy host path for the new verb: a writable database answers a
     * reply row carrying the header and the 12-char prefix, never the full
     * token — the transcript capture sees the same view the TUI does.
     */
    public function testListThroughTheHostReportsLiveSharesByPrefix(): void
    {
        $path = $this->dir . '/session.db';
        $sessions = new EnhancedSessionStore($path);
        $sessions->createSession('home', 'p', 'm', name: 'Home One');
        $token = ShareStore::atPath($path)->publish('home', 'html', '<p>hello</p>', 7)->token;

        $context = CommandContext::new(
            history: [Message::user('hi'), Message::assistant('hello')],
            sessionId: 'home',
            root: $this->dir,
            sessionStore: $sessions,
        );
        $level = ob_get_level();
        $result = (new ShareHostCommand())->run($context, '/share list');

        $this->assertSame($level, ob_get_level());
        $this->assertSame(Role::Assistant, $result->rows[1]->role, 'exit 0 rides the ordinary reply shape');
        $this->assertStringContainsString('Live share pages:', $result->rows[1]->content);
        $this->assertStringContainsString('Home One', $result->rows[1]->content);
        $this->assertStringContainsString(substr($token, 0, 12), $result->rows[1]->content);
        $this->assertStringNotContainsString(substr($token, 12), $result->rows[1]->content);
    }

    /**
     * A live database that simply cannot be opened for the act: build the
     * session store (which creates the file and one row), publish a share
     * so a revoke has a real token to chase, then seal the file at 0444 —
     * the command opens its OWN ShareStore and that connect is what fails.
     */
    private function contextWithReadOnlyDatabase(?string &$token = null): CommandContext
    {
        $path = $this->dir . '/session.db';
        $sessions = new EnhancedSessionStore($path);
        $sessions->createSession('home', 'p', 'm', name: 'Home One');
        $token = ShareStore::atPath($path)->publish('home', 'html', '<p>hello</p>', 7)->token;

        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                chmod($path . $suffix, 0444);
            }
        }

        return CommandContext::new(
            history: [Message::user('hi'), Message::assistant('hello')],
            sessionId: 'home',
            root: $this->dir,
            sessionStore: $sessions,
        );
    }
}

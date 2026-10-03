<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap X-35a: `/share [md|html|json] [path]` writes the session to a local
 * file and names it — `~/.sugar-crush/exports/<session-id>-<UTC ts>.<ext>`
 * (0700 directory, 0600 file) by default, or a path jailed to the project
 * root. HTML is self-contained and escaped.
 */
final class ShareLocalExportTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tempDir = '';

    private string $root = '';

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/share_local_' . bin2hex(random_bytes(6));
        $this->root = $this->tempDir . '/project';
        mkdir($this->root, 0700, true);
        $this->useHomeSandbox($this->tempDir . '/home');

        foreach (['SUGARCRUSH_SHARE_UPLOAD_URL', 'SUGAR_CRUSH_SHARE_UPLOAD_URL'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $original) {
            $original === false ? putenv($name) : putenv($name . '=' . $original);
        }
        $this->restoreHomeSandbox();
        $this->removeShareFixture($this->tempDir);

        parent::tearDown();
    }

    public function testABareShareWritesAPrivateMarkdownFileUnderTheHomeExportsDirectory(): void
    {
        $reply = $this->slashShare('/share');

        $this->assertSame(1, preg_match('/to `([^`]+)`/', $reply->content, $written), 'the reply names the written file');
        $path = $written[1];
        $exports = $this->tempDir . '/home/.sugar-crush/exports';

        $this->assertSame($exports, \dirname($path));
        $this->assertSame(1, preg_match('/^sess-42-\d{8}T\d{6}Z\.md$/', basename($path)), 'session id + UTC stamp + extension');
        $this->assertSame(0700, fileperms($exports) & 0777);
        $this->assertSame(0600, fileperms($path) & 0777, 'a transcript can hold secrets');

        $markdown = (string) file_get_contents($path);
        $this->assertStringContainsString('please fix the bug', $markdown);
        $this->assertStringContainsString('done, see the diff', $markdown);
        $this->assertStringContainsString('3 passed', $markdown, 'tool rows are exported');
        $this->assertStringNotContainsString('a ui-only notice', $markdown, 'app chrome is not');
        $this->assertSame(Role::Assistant, $reply->role);
    }

    public function testHtmlIsSelfContainedAndEscaped(): void
    {
        $reply = $this->slashShare('/share html');
        preg_match('/to `([^`]+)`/', $reply->content, $written);
        $html = (string) file_get_contents($written[1]);

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringEndsWith(".html", $written[1]);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script', $html, 'no script, and the quoted one is text');
        $this->assertDoesNotMatchRegularExpression('/\b(?:src|href)\s*=/i', $html, 'no remote assets');
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    public function testJsonCarriesToolCallsAndToolResults(): void
    {
        $reply = $this->slashShare('/share json');
        preg_match('/to `([^`]+)`/', $reply->content, $written);
        $rows = json_decode((string) file_get_contents($written[1]), true);

        $this->assertIsArray($rows);
        $this->assertCount(4, $rows, 'system prompt row, user, assistant with a call, tool row — no ui-only notice');
        $this->assertSame('Bash', $rows[2]['tool_calls'][0]['name']);
        $this->assertSame('3 passed', $rows[3]['tool_results'][0]['content']);
    }

    public function testAPathInsideTheProjectIsWrittenAndItsExtensionPicksTheFormat(): void
    {
        $reply = $this->slashShare('/share exports/today.html');

        $expected = realpath($this->root) . '/exports/today.html';
        $this->assertStringContainsString("to `{$expected}`", $reply->content);
        $this->assertStringContainsString('as html', $reply->content);
        $this->assertFileExists($expected);
        $this->assertSame(0600, fileperms($expected) & 0777);
    }

    public function testAnExistingDirectoryReceivesTheDefaultFileName(): void
    {
        mkdir($this->root . '/out');

        $reply = $this->slashShare('/share md out');

        $this->assertSame(1, preg_match('/to `([^`]+)`/', $reply->content, $written));
        $this->assertSame(realpath($this->root . '/out'), \dirname($written[1]));
        $this->assertStringEndsWith('.md', $written[1]);
    }

    /**
     * @dataProvider escapingPaths
     */
    public function testAPathOutsideTheProjectIsRefusedAndNothingIsWritten(string $path): void
    {
        $next = $this->dispatch('/share md ' . $path);
        $reply = $next->history[\count($next->history) - 1];

        $this->assertSame(Role::System, $reply->role, 'a refusal is a notice, not a model reply');
        $this->assertStringContainsString('must stay inside the project root', $reply->content);
        $this->assertFileDoesNotExist($this->tempDir . '/escaped.md');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingPaths(): array
    {
        return [
            'dot-dot' => ['../escaped.md'],
            'absolute' => ['/tmp/../' . 'escaped-share-' . 'probe.md'],
        ];
    }

    public function testTheCommandSeamWritesToAnExplicitExportsDirectory(): void
    {
        $dir = $this->tempDir . '/elsewhere';

        ob_start();
        $exit = (new ShareCommand($dir))->execute($this->chat(''), ['json']);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString("to `{$dir}/sess-42-", $output);
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    private function slashShare(string $line): Message
    {
        $next = $this->dispatch($line);
        $reply = $next->history[\count($next->history) - 1];
        $this->assertSame(Role::Assistant, $reply->role, 'a successful export replies as a transcript row: ' . $reply->content);

        return $reply;
    }

    private function dispatch(string $line): Chat
    {
        [$next] = $this->chat($line)->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(Chat::class, $next);

        return $next;
    }

    private function chat(string $draft): Chat
    {
        return new Chat(
            history: [
                Message::system('You are a coding agent.'),
                Message::user('please fix the bug <script>alert(1)</script>'),
                new Message(Role::Assistant, 'done, see the diff', time(), toolCalls: [new ToolCall('Bash', ['command' => 'phpunit'], 'call-1')]),
                new Message(Role::System, 'Bash', time(), toolResults: [ToolResult::ok('Bash', '3 passed', 'call-1')], uiOnly: true),
                Message::notice('a ui-only notice'),
            ],
            inputBuf: $draft,
            backend: new EchoBackend(),
            currentSessionId: 'sess-42',
            projectRoot: $this->root,
        );
    }

    private function removeShareFixture(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}

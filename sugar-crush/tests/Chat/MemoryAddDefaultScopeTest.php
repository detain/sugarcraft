<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Memory\MemoryStore;

/**
 * Roadmap 0.6: `/memory add` and `/memory list` default to the `project`
 * scope, and a note sent to the `agent` scope — which never reaches the prompt
 * — says so in the reply.
 */
final class MemoryAddDefaultScopeTest extends TestCase
{
    private string $root;

    private MemoryStore $store;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/crush_memory_default_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/home', 0o700, true);
        $this->store = new MemoryStore($this->root . '/home');
    }

    protected function tearDown(): void
    {
        $this->removeMemoryDefaultFixture($this->root);
    }

    public function testABareAddIsAProjectNote(): void
    {
        $reply = $this->reply('/memory add run phpunit from the lib root');

        $this->assertStringContainsString('(scope: project)', $reply);
        $this->assertStringNotContainsString('never reach the prompt', $reply);
        $this->assertSame([], $this->store->list('user'), 'nothing went to the user scope');
    }

    public function testABareListShowsWhatABareAddWrote(): void
    {
        $this->reply('/memory add the deploy key rotates monthly');

        $this->assertStringContainsString('the deploy key rotates monthly', $this->reply('/memory list'));
    }

    public function testAnAgentScopeNoteSaysItNeverReachesThePrompt(): void
    {
        $reply = $this->reply('/memory add --scope agent scratch thought');

        $this->assertStringContainsString('(scope: agent)', $reply);
        $this->assertStringContainsString('Agent-scope notes are listable but never reach the prompt', $reply);
    }

    public function testAUserScopeNoteCarriesNoWarning(): void
    {
        $reply = $this->reply('/memory add prefer short answers --scope user');

        $this->assertStringContainsString('(scope: user)', $reply);
        $this->assertStringNotContainsString('never reach the prompt', $reply);
        $this->assertCount(1, $this->store->list('user'));
    }

    public function testTheHelpTextNamesTheProjectDefault(): void
    {
        $help = $this->reply('/memory');

        $this->assertStringContainsString('(default: project)', $help);
        $this->assertStringContainsString('Scopes: `project` (default), `user`, `agent`', $help);
        $this->assertStringNotContainsString('(default: user)', $help);
    }

    private function reply(string $input): string
    {
        [$next] = (new Chat(
            history: [],
            projectRoot: $this->root,
            inputBuf: $input,
            backend: new EchoBackend(),
            memoryStore: $this->store,
        ))->update(new KeyMsg(KeyType::Enter, ''));

        return $next->history[count($next->history) - 1]->content;
    }

    private function removeMemoryDefaultFixture(string $dir): void
    {
        if (!is_dir($dir)) {
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

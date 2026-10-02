<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Support\ProjectRoot;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Two 15d-05 residuals of the repo-root move (w8b-project-root), on the
 * `/memory` commands Chat owns:
 *
 *  - the `/memory import` sentinel was written under the LAUNCH directory
 *    while every other `.sugar-crush/*` lookup walks up to the repository
 *    root, so a subdirectory launch left a second `.sugar-crush/` that a
 *    launch from the top never saw — and the import ran again from there;
 *  - `/memory add --scope project` fell back to the home store silently when
 *    the repository could not host the note, so the user believed a note was
 *    in the checkout that was not.
 */
final class MemoryRepoRootReplyTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp = '';
    private string $repo = '';
    private string $sub = '';
    private MemoryStore $store;
    private string $origErrorLog = '';

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_mem_reporoot_' . bin2hex(random_bytes(6));
        $this->repo = $this->tmp . '/repo';
        $this->sub = $this->repo . '/src/deep';
        mkdir($this->sub, 0o700, true);
        exec('git -C ' . escapeshellarg($this->repo) . ' init -q 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));

        $this->useHomeSandbox($this->tmp . '/home');
        mkdir($this->tmp . '/store', 0o700, true);
        $this->store = new MemoryStore($this->tmp . '/store');
        $this->origErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->tmp . '/error.log');
        ProjectRoot::forget();
    }

    protected function tearDown(): void
    {
        ProjectRoot::forget();
        ini_set('error_log', $this->origErrorLog);
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->tmp) . ' 2>&1', $rmOutput);
    }

    private function reply(string $draft, string $root): string
    {
        [$next] = (new Chat(inputBuf: $draft, backend: new EchoBackend(), memoryStore: $this->store, projectRoot: $root))
            ->update(new KeyMsg(KeyType::Enter));

        return (string) $next->history[array_key_last($next->history)]->content;
    }

    public function testTheImportSentinelLivesUnderTheRepositoryRootOnASubdirectoryLaunch(): void
    {
        mkdir($this->sub . '/.opencode/memory', 0o700, true);
        file_put_contents($this->sub . '/.opencode/memory/note.md', "a note\n");

        $first = $this->reply('/memory import opencode', $this->sub);

        self::assertStringContainsString('Imported 1', $first);
        self::assertFileExists($this->repo . '/.sugar-crush/memory/.imported-opencode', 'the sentinel sits where every other .sugar-crush lookup reads');
        self::assertDirectoryDoesNotExist($this->sub . '/.sugar-crush', 'no second .sugar-crush is planted in the launch subdirectory');

        // A launch from the top of the same repository honours it.
        self::assertStringContainsString('Already imported', $this->reply('/memory import opencode', $this->repo));
    }

    public function testAProjectNoteTheRepositoryCannotHostSaysItFellBackToTheHomeStore(): void
    {
        // A root that does not exist cannot host `.sugar-crush/memory/`.
        $reply = $this->reply('/memory add --scope project remember the deploy key rotation', $this->tmp . '/gone');

        self::assertStringContainsString('Memory created with ID', $reply);
        self::assertStringContainsString('Saved in the home store, not this repository', $reply);
        self::assertCount(1, $this->store->list('project'), 'the note was kept, in the home store');
    }

    public function testAProjectNoteTheRepositoryHostsSaysNothingExtra(): void
    {
        $reply = $this->reply('/memory add --scope project remember the deploy key rotation', $this->repo);

        self::assertStringContainsString('Memory created with ID', $reply);
        self::assertStringNotContainsString('home store', $reply);
        self::assertSame([], $this->store->list('project'), 'the note went to the repository, not the home store');
    }

    public function testAUserNoteNeverMentionsTheFallback(): void
    {
        $reply = $this->reply('/memory add remember this', $this->tmp . '/gone');

        self::assertStringNotContainsString('home store', $reply, 'user scope always lives in the home store; there is nothing to fall back from');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Code\App\ErrorMsg;
use SugarCraft\Code\App\StatusMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\EditorCommand;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14h: `/editor` opens $VISUAL/$EDITOR through sugar-core's
 * Cmd::exec and puts what was saved into the draft box, unsent.
 */
final class EditorCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-editor-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox . '/tmp', 0o700, true);
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        self::removeTree($this->sandbox);
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
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }

    private function chat(string $draft): Chat
    {
        return (new Chat(inputBuf: $draft, backend: new EchoBackend()))->withSize(100, 30);
    }

    /** The ExecRequest a Cmd resolves to. */
    private static function execRequest(?\Closure $cmd): ExecRequest
    {
        self::assertNotNull($cmd);
        $request = $cmd();
        self::assertInstanceOf(ExecRequest::class, $request);

        return $request;
    }

    /** The file operand at the end of an editor command line. */
    private static function fileOf(ExecRequest $request): string
    {
        self::assertIsString($request->command);
        self::assertSame(1, preg_match("/'([^']+)'$/", $request->command, $m));

        return $m[1];
    }

    public function testTheEditorPrecedenceIsVisualThenEditorThenVi(): void
    {
        $command = EditorCommand::new();

        self::assertSame('nvim', $command->withEnvironment(['VISUAL' => 'nvim', 'EDITOR' => 'nano'])->editor());
        self::assertSame('nano', $command->withEnvironment(['VISUAL' => '  ', 'EDITOR' => 'nano'])->editor());
        self::assertSame('code --wait', $command->withEnvironment(['VISUAL' => false, 'EDITOR' => ' code --wait '])->editor());
        self::assertContains($command->withEnvironment([])->editor(), ['vi', 'notepad']);
    }

    public function testTheCmdRunsTheEditorOnAnOwnerOnlyTempFileHoldingTheStartingText(): void
    {
        $request = self::execRequest(
            EditorCommand::new()->withEnvironment(['EDITOR' => 'my-editor -w'], $this->sandbox . '/tmp')->cmd('start here'),
        );
        $file = self::fileOf($request);

        self::assertStringStartsWith('my-editor -w ', $request->command);
        self::assertFalse($request->captureOutput, 'the editor needs the real terminal');
        self::assertStringStartsWith($this->sandbox . '/tmp/sc_editor_', $file);
        self::assertStringEndsWith('.md', $file);
        self::assertSame('start here', file_get_contents($file));
        self::assertSame(0o600, fileperms($file) & 0o777);
    }

    public function testWhatTheEditorSavedComesBackAsOnePasteAndTheFileIsRemoved(): void
    {
        $request = self::execRequest(EditorCommand::new()->withEnvironment(['EDITOR' => 'ed'], $this->sandbox . '/tmp')->cmd());
        $file = self::fileOf($request);
        file_put_contents($file, "line one\nline two\n");

        $msg = ($request->onComplete)(0, '', '', null);

        self::assertInstanceOf(PasteMsg::class, $msg);
        self::assertSame("line one\nline two", $msg->content);
        self::assertFileDoesNotExist($file);
    }

    public function testAFailedEditorLeavesTheDraftAndSaysSo(): void
    {
        $request = self::execRequest(EditorCommand::new()->withEnvironment(['EDITOR' => 'broken'], $this->sandbox . '/tmp')->cmd('x'));
        $file = self::fileOf($request);

        $exited = ($request->onComplete)(2, '', '', null);
        self::assertInstanceOf(ErrorMsg::class, $exited);
        self::assertStringContainsString('broken exited with status 2', $exited->message);
        self::assertFileDoesNotExist($file);

        $unstarted = EditorCommand::collect($file, 'broken', -1, new \RuntimeException('no such file'));
        self::assertInstanceOf(ErrorMsg::class, $unstarted);
        self::assertStringContainsString('could not be started (no such file)', $unstarted->message);
    }

    public function testAnEmptyFileChangesNothing(): void
    {
        $request = self::execRequest(EditorCommand::new()->withEnvironment(['EDITOR' => 'ed'], $this->sandbox . '/tmp')->cmd());
        file_put_contents(self::fileOf($request), "\n\n");

        self::assertInstanceOf(StatusMsg::class, ($request->onComplete)(0, '', '', null));
    }

    public function testAnUnwritableTempDirAnswersWithAnError(): void
    {
        $cmd = EditorCommand::new()->withEnvironment(['EDITOR' => 'ed'], $this->sandbox . '/missing/dir')->cmd();

        self::assertInstanceOf(ErrorMsg::class, $cmd());
    }

    public function testSlashEditorEmptiesTheBoxAndReturnsTheExecCmd(): void
    {
        putenv('VISUAL');
        $previous = getenv('EDITOR');
        putenv('EDITOR=true-editor');

        try {
            [$next, $cmd] = $this->chat('/editor draft words')->update(new KeyMsg(KeyType::Enter));
        } finally {
            $previous === false ? putenv('EDITOR') : putenv('EDITOR=' . $previous);
        }

        self::assertFalse($next->inFlight, '/editor composes; it does not send');
        self::assertSame('', $next->inputBuf);
        $request = self::execRequest($cmd);
        self::assertStringStartsWith('true-editor ', $request->command);
        $file = self::fileOf($request);
        self::assertSame('draft words', file_get_contents($file));

        // The round trip: the editor's result re-enters update() as a paste.
        file_put_contents($file, "composed prompt\n");
        [$after] = $next->update(($request->onComplete)(0, '', '', null));
        self::assertSame('composed prompt', $after->inputBuf);
        self::assertFalse($after->inFlight);
    }

    public function testEditorIsAdvertisedWithItsHint(): void
    {
        $rows = array_values(array_filter(CommandRegistry::slashCommands(), static fn($s): bool => $s->name === 'editor'));

        self::assertCount(1, $rows);
        self::assertSame('[text]', $rows[0]->argumentHint);
    }

    /**
     * VIS-2b: `/memory open` reuses this class with a different destination —
     * the file itself, not the draft box. The Cmd is still plain Cmd::exec
     * (that channel is what hands the terminal over and back), and the
     * completion row names the file without reading it back.
     */
    public function testOpenFilePutsTheGivenPathOnTheExecChannel(): void
    {
        $cmd = EditorCommand::new()
            ->withEnvironment(['EDITOR' => 'ed'], $this->sandbox . '/tmp')
            ->openFile($this->sandbox . '/note.md');
        $request = self::execRequest($cmd);

        self::assertSame("ed '" . $this->sandbox . "/note.md'", $request->command);
        self::assertFalse($request->captureOutput);
    }

    public function testOpenCollectRowsNameTheFileOrTheFailure(): void
    {
        $closed = EditorCommand::openCollect('/tmp/n.md', 'vim', 0, null);
        self::assertInstanceOf(StatusMsg::class, $closed);
        self::assertStringContainsString('/tmp/n.md', $closed->message);

        $exited = EditorCommand::openCollect('/tmp/n.md', 'vim', 3, null);
        self::assertInstanceOf(ErrorMsg::class, $exited);
        self::assertStringContainsString('unchanged', $exited->message);

        $unstarted = EditorCommand::openCollect('/tmp/n.md', 'vim', 0, new \RuntimeException('no tty'));
        self::assertInstanceOf(ErrorMsg::class, $unstarted);
        self::assertStringContainsString('no tty', $unstarted->message);
    }
}

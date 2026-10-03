<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Crush\App\ErrorMsg;
use SugarCraft\Crush\App\StatusMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\EditorCommand;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.14h: `/editor` opens $VISUAL/$EDITOR through candy-core's
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
}

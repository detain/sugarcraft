<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\TextArea;

use SugarCraft\Bits\TextArea\TextArea;
use SugarCraft\Bits\TextArea\TextAreaEditedMsg;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class TextAreaEditorTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Pin EDITOR to a binary that's guaranteed present on POSIX so
        // discovery succeeds inside the Cmd builder. Tests never invoke
        // the underlying ExecRequest, so the real binary is not run.
        foreach (['VISUAL', 'EDITOR'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        putenv('EDITOR=true');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        $this->savedEnv = [];
    }

    private function focused(string $initial = ''): TextArea
    {
        $t = TextArea::new();
        if ($initial !== '') {
            $t = $t->setValue($initial);
        }
        [$t, ] = $t->focus();
        return $t;
    }

    public function testCtrlOReturnsExecRequestWithEditorArgvAndTempFile(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('hello world');
        [$next, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $this->assertSame($t, $next, 'Ctrl+O does not mutate state synchronously');
        $this->assertNotNull($cmd);

        $msg = $cmd();
        $this->assertInstanceOf(ExecRequest::class, $msg);
        $this->assertIsArray($msg->command);
        $this->assertGreaterThanOrEqual(2, count($msg->command));
        $this->assertStringContainsString('true', $msg->command[0]);

        $tmp = $msg->command[count($msg->command) - 1];
        $this->assertIsString($tmp);
        $this->assertStringEndsWith('.txt', $tmp);
        $this->assertFileExists($tmp);
        $this->assertSame('hello world', file_get_contents($tmp));

        // Drain the onComplete cleanup so the temp file doesn't leak.
        ($msg->onComplete)(0, '', '', null);
    }

    public function testCtrlOIsNoOpWhenUnfocused(): void
    {
        $t = TextArea::new()->setValue('hello'); // not focused
        [$next, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testTextAreaEditedMsgReplacesValue(): void
    {
        $t = $this->focused('original');
        [$next, $cmd] = $t->update(new TextAreaEditedMsg("new\ncontents"));
        $this->assertNull($cmd);
        $this->assertSame("new\ncontents", $next->value());
    }

    public function testTextAreaEditedMsgAppliesEvenWhenUnfocused(): void
    {
        // The editor exits while the model has temporarily been blurred —
        // the result still has to land. Mirrors upstream behaviour.
        $t = TextArea::new()->setValue('original');
        [$next, ] = $t->update(new TextAreaEditedMsg('replaced'));
        $this->assertSame('replaced', $next->value());
    }

    public function testOnCompleteWithZeroExitProducesEditedMsgFromTempFile(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('seed');
        [, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $req = $cmd();
        $tmp = $req->command[count($req->command) - 1];

        // Simulate the editor writing new content and exiting cleanly.
        file_put_contents($tmp, 'edited by user');
        $produced = ($req->onComplete)(0, '', '', null);

        $this->assertInstanceOf(TextAreaEditedMsg::class, $produced);
        $this->assertSame('edited by user', $produced->value);
        $this->assertFileDoesNotExist($tmp);
    }

    public function testOnCompleteWithNonZeroExitReturnsNull(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('seed');
        [, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $req = $cmd();
        $tmp = $req->command[count($req->command) - 1];

        // vim :cq path — non-zero exit, no Msg produced, temp file unlinked.
        $this->assertNull(($req->onComplete)(1, '', '', null));
        $this->assertFileDoesNotExist($tmp);
    }

    public function testOnCompleteWithThrowableReturnsNull(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('seed');
        [, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $req = $cmd();
        $tmp = $req->command[count($req->command) - 1];

        $this->assertNull(($req->onComplete)(0, '', '', new \RuntimeException('boom')));
        $this->assertFileDoesNotExist($tmp);
    }

    public function testWithEditorExtensionAppliesToTempFileSuffix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('# heading')->withEditorExtension('.md');
        [, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $req = $cmd();
        $tmp = $req->command[count($req->command) - 1];

        $this->assertStringEndsWith('.md', $tmp);
        ($req->onComplete)(0, '', '', null);
    }

    public function testWithEditorExtensionAcceptsBareSuffix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX-only: discovery falls back to vi/nano');
        }

        $t = $this->focused('seed')->withEditorExtension('json');
        [, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
        $req = $cmd();
        $tmp = $req->command[count($req->command) - 1];
        $this->assertStringEndsWith('.json', $tmp);
        ($req->onComplete)(0, '', '', null);
    }

    public function testEditorExtensionShortAliasMirrorsLongForm(): void
    {
        $a = TextArea::new()->withEditorExtension('.go');
        $b = TextArea::new()->editorExtension('.go');
        $this->assertSame($a->editorExtension, $b->editorExtension);
        $this->assertSame('.go', $a->editorExtension);
    }

    public function testDefaultEditorExtensionIsTxt(): void
    {
        $this->assertSame('.txt', TextArea::new()->editorExtension);
    }

    public function testCtrlOReturnsNullCmdWhenNoEditorAvailable(): void
    {
        // Force discovery to fail by clobbering both env-var candidates
        // AND wiping PATH so vi / nano can't be located either.
        putenv('EDITOR=__sugarcraft_no_such_editor__');
        $savedPath = getenv('PATH');
        putenv('PATH=');

        try {
            $t = $this->focused('seed');
            [$next, $cmd] = $t->update(new KeyMsg(KeyType::Char, 'o', ctrl: true));
            $this->assertSame($t, $next);
            $this->assertNull($cmd);
        } finally {
            if ($savedPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH=' . $savedPath);
            }
        }
    }
}

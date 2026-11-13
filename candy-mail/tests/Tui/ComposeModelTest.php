<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Mail\Email;
use SugarCraft\Mail\Tui\ComposeModel;
use SugarCraft\Mail\Tui\SendResultMsg;

/**
 * CM-33: the compose form, driven through update()/view() directly. No
 * network and no terminal: the send/render seams are recorders.
 */
final class ComposeModelTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    private function model(array $prefill = [], ?\Closure $send = null): ComposeModel
    {
        $send ??= function (Email $e): string {
            $this->sent[] = $e;
            return 'fake';
        };

        return ComposeModel::new($send, static fn (Email $e): string => "Subject: " . $e->subject . "\r\n\r\nbody", $prefill + [
            'from' => 'me@example.com', 'to' => 'you@example.com',
        ]);
    }

    private function press(ComposeModel $m, string $key): ComposeModel
    {
        [$next] = $this->step($m, $key);
        return $next;
    }

    /** @return array{0: ComposeModel, 1: ?\Closure} */
    private function step(ComposeModel $m, string $key): array
    {
        $msg = match ($key) {
            'tab' => new KeyMsg(KeyType::Tab),
            'shift+tab' => new KeyMsg(KeyType::Tab, shift: true),
            'enter' => new KeyMsg(KeyType::Enter),
            'up' => new KeyMsg(KeyType::Up),
            'down' => new KeyMsg(KeyType::Down),
            'left' => new KeyMsg(KeyType::Left),
            'right' => new KeyMsg(KeyType::Right),
            'home' => new KeyMsg(KeyType::Home),
            'end' => new KeyMsg(KeyType::End),
            'backspace' => new KeyMsg(KeyType::Backspace),
            'delete' => new KeyMsg(KeyType::Delete),
            'space' => new KeyMsg(KeyType::Space),
            'escape' => new KeyMsg(KeyType::Escape),
            'ctrl+s', 'ctrl+p', 'ctrl+q', 'ctrl+c', 'ctrl+u' => new KeyMsg(KeyType::Char, \substr($key, 5), ctrl: true),
            default => new KeyMsg(KeyType::Char, $key),
        };
        [$next, $cmd] = $m->update($msg);
        $this->assertInstanceOf(ComposeModel::class, $next);

        return [$next, $cmd];
    }

    private function type(ComposeModel $m, string $text): ComposeModel
    {
        foreach (\mb_str_split($text) as $ch) {
            $m = $this->press($m, $ch === ' ' ? 'space' : $ch);
        }
        return $m;
    }

    public function testIsAModelWithNoInitCmdOrSubscriptions(): void
    {
        $m = $this->model();
        $this->assertInstanceOf(Model::class, $m);
        $this->assertNull($m->init());
        $this->assertNull($m->subscriptions());
        $this->assertSame('from', $m->focused());
    }

    public function testPrefillFlowsIntoTheComposedEmail(): void
    {
        $m = $this->model(['cc' => 'c@example.com, d@example.com', 'bcc' => 'e@example.com', 'subject' => ' Hi ', 'body' => 'text']);
        $e = $m->email();
        $this->assertSame(['me@example.com'], $e->from);
        $this->assertSame(['you@example.com'], $e->to);
        $this->assertSame(['c@example.com', 'd@example.com'], $e->cc);
        $this->assertSame(['e@example.com'], $e->bcc);
        $this->assertSame('Hi', $e->subject);
        $this->assertSame('text', $e->body);
    }

    public function testEmailReadsAttachmentPathsAndRefusesMissingOnes(): void
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        $e = $this->model(['attach' => $path . ' , '])->email();
        $this->assertCount(1, $e->attachments);
        $this->assertSame('composer.json', $e->attachments[0]->filename);

        $this->expectException(\RuntimeException::class);
        $this->model(['attach' => '/nonexistent/zzz.bin'])->email();
    }

    public function testHtmlAndReplyToFieldsFeedTheEmail(): void
    {
        $m = $this->model(['replyto' => ' help@example.com ', 'html' => '<p>hi</p>']);
        $e = $m->email();
        $this->assertSame('help@example.com', $e->replyTo);
        $this->assertSame('<p>hi</p>', $e->htmlBody);

        $blank = $this->model()->email();
        $this->assertNull($blank->replyTo);
        $this->assertNull($blank->htmlBody);
    }

    public function testHtmlFieldIsMultilineAndEditable(): void
    {
        $m = $this->model();
        while ($m->focused() !== 'html') {
            $m = $this->press($m, 'tab');
        }
        $m = $this->press($m, 'enter');
        $this->assertSame('html', $m->focused(), 'enter inside html is a newline, not a field change');
        $this->assertSame("\n", $m->fields['html']->value());
    }

    public function testReplyToFieldIsEditableAndRendered(): void
    {
        $m = $this->model();
        while ($m->focused() !== 'replyto') {
            $m = $this->press($m, 'tab');
        }
        $m = $this->press($m, 'space');
        $this->assertSame(' ', $m->fields['replyto']->value());
        $view = $m->view();
        $this->assertStringContainsString('Reply-To', $view);
        $this->assertStringContainsString('HTML', $view);
    }

    public function testTabShiftTabEnterUpDownMoveFocusAndWrap(): void
    {
        $m = $this->model();
        $m = $this->press($m, 'tab');
        $this->assertSame('to', $m->focused());
        $m = $this->press($m, 'shift+tab');
        $m = $this->press($m, 'shift+tab');
        $this->assertSame('attach', $m->focused(), 'wraps backwards');
        $m = $this->press($m, 'tab');
        $this->assertSame('from', $m->focused(), 'wraps forwards');
        $m = $this->press($m, 'enter');
        $this->assertSame('to', $m->focused());
        $m = $this->press($this->press($m, 'down'), 'up');
        $this->assertSame('to', $m->focused());
    }

    public function testTypingEditsTheFocusedFieldOnly(): void
    {
        $m = $this->model(['to' => '']);
        $m = $this->press($m, 'tab');
        $m = $this->type($m, 'a b');
        $this->assertSame('a b', $m->fields['to']->value());
        $this->assertSame('me@example.com', $m->fields['from']->value());
        $m = $this->press($this->press($m, 'backspace'), 'left');
        $m = $this->press($m, 'delete');
        $this->assertSame('a', $m->fields['to']->value());
        $m = $this->press($this->press($m, 'home'), 'end');
        $m = $this->press($this->press($m, 'ctrl+u'), 'right');
        $this->assertTrue($m->fields['to']->isEmpty());
        $this->assertTrue($m->dirty);
    }

    public function testBodyTakesNewlinesAndVerticalMovement(): void
    {
        $m = $this->model();
        for ($i = 0; $i < 6; $i++) {
            $m = $this->press($m, 'tab');
        }
        $this->assertSame('body', $m->focused());
        $m = $this->type($m, 'ab');
        $m = $this->press($m, 'enter');
        $m = $this->type($m, 'cd');
        $this->assertSame("ab\ncd", $m->fields['body']->value());
        $m = $this->press($m, 'up');
        $this->assertSame('body', $m->focused(), 'up inside the body moves the caret, not the field');
        $m = $this->press($m, 'up');
        $this->assertSame('subject', $m->focused(), 'up on the first line leaves the field');
    }

    public function testPasteInsertsSanitisedTextAndIsIgnoredWhileSending(): void
    {
        $m = $this->model(['to' => '']);
        $m = $this->press($m, 'tab');
        [$m] = $m->update(new PasteMsg("a@b.c,\x1b d@e.f\n"));
        $this->assertSame('a@b.c, d@e.f ', $m->fields['to']->value());
    }

    public function testSendDispatchesACmdThatRunsTheSenderAndReportsTheTransport(): void
    {
        $m = $this->model(['body' => 'hello']);
        [$m, $cmd] = $this->step($m, 'ctrl+s');
        $this->assertSame('sending', $m->mode);
        $this->assertSame([], $this->sent, 'update() itself never sends');
        $this->assertInstanceOf(\Closure::class, $cmd);

        $result = $cmd();
        $this->assertInstanceOf(SendResultMsg::class, $result);
        $this->assertNull($result->error);
        $this->assertSame('fake', $result->transport);
        $this->assertCount(1, $this->sent);

        [$m] = $m->update($result);
        $this->assertSame('edit', $m->mode);
        $this->assertFalse($m->dirty);
        $this->assertFalse($m->statusIsError);
        $this->assertStringContainsString('fake', $m->status);
    }

    public function testKeysAreIgnoredWhileSendingUntilTheResultArrives(): void
    {
        [$m] = $this->step($this->model(['body' => 'x']), 'ctrl+s');
        $m = $this->press($m, 'z');
        $this->assertSame('sending', $m->mode);
        $this->assertStringNotContainsString('z', $m->fields['from']->value());
    }

    public function testSenderFailureComesBackAsAnErrorResultAndKeepsTheForm(): void
    {
        $m = $this->model(['body' => 'x'], static function (Email $e): string {
            throw new \RuntimeException('relay said no');
        });
        [$m, $cmd] = $this->step($m, 'ctrl+s');
        $result = $cmd();
        $this->assertSame('relay said no', $result->error);

        [$m] = $m->update($result);
        $this->assertSame('edit', $m->mode);
        $this->assertTrue($m->statusIsError);
        $this->assertStringContainsString('relay said no', $m->status);
        $this->assertSame('x', $m->fields['body']->value());
    }

    public function testInvalidFormRefusesLocallyWithoutACmd(): void
    {
        $m = $this->model(['to' => 'not-an-address']);
        [$m, $cmd] = $this->step($m, 'ctrl+s');
        $this->assertNull($cmd);
        $this->assertTrue($m->statusIsError);
        $this->assertSame('edit', $m->mode);
        $this->assertSame([], $this->sent);
    }

    public function testPreviewTogglesAndRendersOfflineThroughTheSeam(): void
    {
        $m = $this->model(['subject' => 'Hello', 'body' => 'b']);
        $m = $this->press($m, 'ctrl+p');
        $this->assertSame('preview', $m->mode);
        $this->assertStringContainsString('Subject: Hello', $m->view());
        $m = $this->press($m, 'z');
        $this->assertSame('preview', $m->mode, 'typing is inert in the preview');
        $m = $this->press($m, 'escape');
        $this->assertSame('edit', $m->mode);
        $this->assertSame([], $this->sent);
    }

    public function testPreviewOfAnInvalidFormStaysInEditWithAnError(): void
    {
        $m = $this->press($this->model(['to' => 'bad']), 'ctrl+p');
        $this->assertSame('edit', $m->mode);
        $this->assertTrue($m->statusIsError);
    }

    public function testQuitIsImmediateWhenCleanAndNeedsConfirmationWhenDirty(): void
    {
        [, $cmd] = $this->step($this->model(), 'ctrl+q');
        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd());

        $dirty = $this->type($this->model(), 'x');
        [$armed, $cmd] = $this->step($dirty, 'ctrl+q');
        $this->assertNull($cmd);
        $this->assertTrue($armed->quitArmed);
        $this->assertStringContainsString('Ctrl+Q', $armed->status);
        [, $cmd] = $this->step($armed, 'ctrl+c');
        $this->assertInstanceOf(\Closure::class, $cmd);

        $disarmed = $this->press($armed, 'tab');
        $this->assertFalse($disarmed->quitArmed, 'any other key withdraws the arming');

        [, $cmd] = $this->step($this->model(), 'escape');
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testWindowSizeIsStoredAndUnknownMessagesAreInert(): void
    {
        [$m] = $this->model()->update(new WindowSizeMsg(100, 40));
        $this->assertSame([100, 40], [$m->cols, $m->rows]);
        [$same, $cmd] = $m->update(new class implements \SugarCraft\Core\Msg {
        });
        $this->assertSame($m, $same);
        $this->assertNull($cmd);
    }

    public function testViewPaintsLabelsStatusAndStaysWithinTheWidth(): void
    {
        $m = $this->model(['subject' => \str_repeat('s', 300)]);
        [$m] = $m->update(new WindowSizeMsg(40, 24));
        $m = $this->press($m, 'ctrl+u');
        $view = $m->view();
        foreach (['From', 'To', 'Cc', 'Bcc', 'Subject', 'Body', 'Attachments', 'compose'] as $label) {
            $this->assertStringContainsString($label, $view);
        }
        $this->assertStringContainsString('comma-separated', $view, 'unfocused empty attach field hints');

        $m = $this->model(['subject' => \str_repeat('s', 300)]);
        [$m] = $m->update(new WindowSizeMsg(40, 24));
        foreach (\explode("\n", \preg_replace('/\x1b\[[0-9;]*m/', '', $m->view())) as $line) {
            $this->assertLessThanOrEqual(40, \mb_strwidth($line));
        }
    }

    public function testErrorStatusIsSanitisedAtPaintTime(): void
    {
        [$m] = $this->model()->update(new SendResultMsg("boom\x1b]0;pwned\x07"));
        $this->assertStringNotContainsString("\x1b]0;pwned", $m->view());
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentMessage;
use SugarCraft\Code\Agents\Live\MessageMode;
use SugarCraft\Code\Agents\Live\SessionMailbox;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Session\SessionKind;
use SugarCraft\Code\Session\SessionStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\ToolCall;
use SugarCraft\Code\Tools\BuiltIn\SendMessageTool;

/**
 * C-AGT-4.e: `SendMessage` with a `session:<id>` target reaches another
 * session through the on-disk {@see SessionMailbox} — only the session's own
 * agent may send, only from the same project root, never to a sub-agent or a
 * `/bg` daemon, and the arriving message is a followup frame that carries no
 * authority.
 */
final class SendMessageCrossSessionTest extends TestCase
{
    private string $base;

    private string $root;

    private string $mailRoot;

    private string $main;

    private string $target;

    private SessionStore $store;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/sc_send_xsession_' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/proj';
        $this->mailRoot = $this->base . '/mail';
        self::assertNotFalse(mkdir($this->root, 0o700, true));
        self::assertNotFalse(mkdir($this->mailRoot, 0o700, true));

        $this->main = 'xm-' . bin2hex(random_bytes(5));
        $this->target = 'xt-' . bin2hex(random_bytes(5));
        $this->store = new SessionStore($this->base . '/session.db');
        $this->store->createSession($this->main, 'p', 'm', null, 'main', $this->root);
        $this->store->createSession($this->target, 'p', 'm', null, 'peer', $this->root);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->base);
    }

    public function testAMainSessionMessagesAnotherSessionAtTheSameRoot(): void
    {
        $inbox = SessionMailbox::forSession($this->target, $this->mailRoot);
        self::assertNotNull($inbox);

        $result = $this->tool()->execute([
            'id' => 'm1',
            'to' => SendMessageTool::SESSION_PREFIX . $this->target,
            'text' => 'rename the module first',
            'mode' => 'followup',
        ]);

        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString($this->target, $result->content());
        self::assertStringContainsString('Queued for session', $result->content());

        $taken = $inbox->take();
        self::assertCount(1, $taken);
        self::assertSame(AgentMessage::FROM_AGENT_PREFIX . $this->main, $taken[0]->from);
        self::assertSame('rename the module first', $taken[0]->text);
        self::assertSame(MessageMode::Followup, $taken[0]->mode);

        $frame = SessionMailbox::frame($taken[0]);
        self::assertStringStartsWith('<session-message from="' . $this->main . '" mode="followup">', $frame);
        self::assertStringContainsString('never user approval', $frame);
    }

    public function testASubAgentCannotReachAnotherSession(): void
    {
        $result = $this->tool()->asChildOf('kid-1', $this->main, $this->main)
            ->execute(['id' => 'm1', 'to' => SendMessageTool::SESSION_PREFIX . $this->target, 'text' => 'hello']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('A sub-agent cannot message another session', $result->content());
    }

    public function testSelfUnknownAndMalformedTargetsRefuse(): void
    {
        $tool = $this->tool();

        $self = $tool->execute(['id' => 'a', 'to' => SendMessageTool::SESSION_PREFIX . $this->main, 'text' => 'here']);
        self::assertTrue($self->isError());
        self::assertStringContainsString('already speaking here', $self->content());

        $ghost = $tool->execute(['id' => 'b', 'to' => SendMessageTool::SESSION_PREFIX . 'no-such-session', 'text' => 'there']);
        self::assertTrue($ghost->isError());
        self::assertStringContainsString('No stored session', $ghost->content());

        $bad = $tool->execute(['id' => 'c', 'to' => SendMessageTool::SESSION_PREFIX . 'not/valid', 'text' => 'x']);
        self::assertTrue($bad->isError());
        self::assertStringContainsString('is not a session id', $bad->content());
    }

    public function testABackgroundDaemonHasNoInboxToMessage(): void
    {
        $bg = $this->store->createChildSession($this->main, SessionKind::Background, 'runner', 'call-1', 'p', 'm');

        $result = $this->tool()->execute(['id' => 'm1', 'to' => SendMessageTool::SESSION_PREFIX . $bg, 'text' => 'wake']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('background daemon', $result->content());
    }

    public function testASessionAtAnotherRootIsForeign(): void
    {
        $elsewhere = $this->base . '/other-proj';
        self::assertNotFalse(mkdir($elsewhere, 0o700, true));
        $stranger = 'xs-' . bin2hex(random_bytes(5));
        $this->store->createSession($stranger, 'p', 'm', null, 'stranger', $elsewhere);

        $result = $this->tool()->execute(['id' => 'm1', 'to' => SendMessageTool::SESSION_PREFIX . $stranger, 'text' => 'cross']);

        self::assertTrue($result->isError());
        self::assertStringContainsString('different project root', $result->content());
    }

    public function testAnInboxThatNeverOpenedRefusesTheSend(): void
    {
        $result = $this->tool()->execute([
            'id' => 'm1',
            'to' => SendMessageTool::SESSION_PREFIX . $this->target,
            'text' => 'nobody home',
            'mode' => 'note',
        ]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('was not delivered', $result->content());
        self::assertStringContainsString('no open inbox', $result->content());
    }

    public function testAPlainTargetIsStillTheNoAskShapeAndASessionTargetIsAsked(): void
    {
        $probe = new \ReflectionMethod(PermissionGate::class, 'isNoAskTool');
        $probe->setAccessible(true);
        $gate = new PermissionGate(PermissionMode::Default);

        self::assertTrue($probe->invoke($gate, new ToolCall(SendMessageTool::NAME, ['to' => 'kid-1', 'text' => 'go'], 'c1')));
        self::assertFalse($probe->invoke($gate, new ToolCall(SendMessageTool::NAME, ['to' => SendMessageTool::SESSION_PREFIX . 'other', 'text' => 'go'], 'c2')));
    }

    private function tool(): SendMessageTool
    {
        $engine = EngineBackend::new(new ScriptedProvider([]), 'm')->withSessionId($this->main);

        return SendMessageTool::new()
            ->withEngine($engine)
            ->withRoot($this->mailRoot)
            ->withSessionRoot($this->root, $this->store);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\PermissionModeToggledMsg;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;

/**
 * Roadmap 5.7-1 (decision D8): `Alt+M` switches the session's gate into and
 * out of plan mode, between turns, telling the model once.
 */
final class PlanModeToggleTest extends TestCase
{
    private HookManager $hooks;

    protected function setUp(): void
    {
        $this->hooks = new HookManager(new HookRegistry());
    }

    private function chat(PermissionMode $mode = PermissionMode::Default, array $history = []): Chat
    {
        $gate = new PermissionGate($mode, [], null, 'the built-in default');
        $this->hooks->register(new PermissionGateHook($gate));

        return (new Chat(
            history: $history,
            backend: EngineBackend::new(new ScriptedProvider([]), 'm')->withPermissionGate($gate),
            hooks: $this->hooks,
        ))->withSize(100, 30);
    }

    private static function altM(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, 'm', alt: true);
    }

    private function chainMode(): ?PermissionMode
    {
        $hook = $this->hooks->hook(HookEvent::PreToolUse->value, PermissionGateHook::NAME);

        return $hook instanceof PermissionGateHook ? $hook->gate()->mode() : null;
    }

    /** @return list<Message> */
    private static function modelVisibleNotices(Chat $chat): array
    {
        return array_values(array_filter(
            $chat->history,
            static fn (Message $m): bool => !$m->uiOnly && $m->role === Role::System
                && str_starts_with($m->content, Chat::MODE_NOTICE_PREFIX),
        ));
    }

    public function testAltMSwapsTheGateInTheHookChainAndTheBackend(): void
    {
        [$next] = $this->chat()->update(self::altM());

        self::assertSame(PermissionMode::Plan, $next->currentPermissionMode());
        self::assertSame(PermissionMode::Plan, $this->chainMode());
        $backend = $next->backend();
        self::assertInstanceOf(EngineBackend::class, $backend);
        self::assertSame(PermissionMode::Plan, $backend->permissionGate()?->mode());
        self::assertSame(Chat::MODE_SWITCH_SOURCE, $backend->permissionGate()?->modeSource());
    }

    public function testTheModelIsToldOnceWithWhatTheNewModeAllows(): void
    {
        [$next] = $this->chat(PermissionMode::Default, [Message::user('hi'), Message::assistant('hello')])
            ->update(self::altM());

        $notices = self::modelVisibleNotices($next);
        self::assertCount(1, $notices);
        self::assertSame(Chat::modeChangeNotice(PermissionMode::Default, PermissionMode::Plan), $notices[0]->content);
        self::assertStringContainsString(PermissionMode::Plan->description(), $notices[0]->content);
    }

    public function testASecondPressReturnsToTheModeItLeftAndCancelsTheUnsentNotice(): void
    {
        [$plan] = $this->chat(PermissionMode::AcceptEdits)->update(self::altM());
        [$back] = $plan->update(self::altM());

        self::assertSame(PermissionMode::AcceptEdits, $back->currentPermissionMode());
        self::assertSame(PermissionMode::AcceptEdits, $this->chainMode());
        self::assertSame([], self::modelVisibleNotices($back), 'a round trip before anything was sent tells the model nothing');
        $last = self::lastRow($back);
        self::assertTrue($last->uiOnly, 'the user is still told what happened');
    }

    public function testASecondSwitchBeforeSendingSupersedesTheFirstNotice(): void
    {
        [$plan] = $this->chat()->update(self::altM());
        [$edits] = $plan->update(new PermissionModeToggledMsg(PermissionMode::AcceptEdits));

        $notices = self::modelVisibleNotices($edits);
        self::assertCount(1, $notices);
        self::assertSame(Chat::modeChangeNotice(PermissionMode::Default, PermissionMode::AcceptEdits), $notices[0]->content);
    }

    public function testASwitchAfterTheModelWasSpokenToAddsANewNotice(): void
    {
        [$plan] = $this->chat()->update(self::altM());
        $sent = $plan->update(new PermissionModeToggledMsg(PermissionMode::Plan))[0];
        // A user message after the notice means the model has been sent it.
        $spoken = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($sent, ['history' => [...$sent->history, Message::user('go on')]]);
        [$back] = $spoken->update(self::altM());

        $notices = self::modelVisibleNotices($back);
        self::assertCount(2, $notices);
        self::assertSame(Chat::modeChangeNotice(PermissionMode::Plan, PermissionMode::Default), $notices[1]->content);
    }

    public function testThePlanToggleFromASessionStartedInPlanGoesToDefault(): void
    {
        [$next] = $this->chat(PermissionMode::Plan)->update(self::altM());

        self::assertSame(PermissionMode::Default, $next->currentPermissionMode());
    }

    public function testAMidTurnSwitchIsRefused(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);
        $this->hooks->register(new PermissionGateHook($gate));
        $chat = (new Chat(
            history: [Message::user('go')],
            backend: EngineBackend::new(new ScriptedProvider([]), 'm')->withPermissionGate($gate),
            hooks: $this->hooks,
            inFlight: true,
            generation: 1,
        ))->withSize(100, 30);

        [$next] = $chat->update(new PermissionModeToggledMsg());

        self::assertSame(PermissionMode::Default, $next->currentPermissionMode());
        self::assertSame(PermissionMode::Default, $this->chainMode());
        self::assertSame([], self::modelVisibleNotices($next));
        self::assertStringContainsString('while a turn runs', self::lastRow($next)->content);
    }

    public function testAChatWithoutAGateSaysSo(): void
    {
        [$next] = (new Chat(backend: new EchoBackend()))->withSize(100, 30)->update(self::altM());

        self::assertNull($next->currentPermissionMode());
        self::assertStringContainsString('no mode to switch', self::lastRow($next)->content);
    }

    public function testTheStatusBarShowsPlanModeAndNotDefault(): void
    {
        $chat = $this->chat();
        self::assertStringNotContainsString('plan mode', self::lastLine($chat));

        [$plan] = $chat->update(self::altM());
        self::assertStringContainsString('plan mode (Alt+M to leave)', self::lastLine($plan));
    }

    public function testTheBadgeNeverWidensTheBarPastTheTerminal(): void
    {
        [$plan] = $this->chat()->update(self::altM());

        foreach ([20, 30, 40, 60, 80, 120] as $cols) {
            $line = self::lastLine($plan->withSize($cols, 20));
            self::assertLessThanOrEqual($cols, \SugarCraft\Core\Util\Width::string($line), "at {$cols} columns");
        }
    }

    private static function lastRow(Chat $chat): Message
    {
        $history = $chat->history;
        $last = end($history);
        self::assertInstanceOf(Message::class, $last);

        return $last;
    }

    private static function lastLine(Chat $chat): string
    {
        $view = $chat->view();
        $body = \is_string($view) ? $view : $view->body;
        $lines = explode("\n", $body);

        return \SugarCraft\Core\Util\Ansi::strip((string) end($lines));
    }
}

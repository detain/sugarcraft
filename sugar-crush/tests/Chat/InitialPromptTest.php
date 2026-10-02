<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\InitialPromptMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Audit CLI-2(b): `sugarcrush fix the login bug` opens the TUI and sends
 * `fix the login bug` as the session's first prompt, the way `claude "<prompt>"`
 * does. {@see \SugarCraft\Crush\Cli\ArgvParser::resolveOperands()} turns the
 * leftover words into {@see \SugarCraft\Crush\Cli\ParsedArgs::$initialPrompt}
 * (ArgvParserPositionalTest), `bin/sugarcrush` hands it to
 * {@see \SugarCraft\Crush\Cli\Bootstrap::app()}, and the Chat submits it from
 * {@see Chat::init()} — the half pinned here.
 */
final class InitialPromptTest extends TestCase
{
    public function testInitDeliversThePromptAndUpdateSubmitsItAsTheFirstTurn(): void
    {
        $chat = (new Chat())->withInitialPrompt('fix the login bug');

        $msg = self::initialPromptMsg($chat);
        self::assertSame('fix the login bug', $msg->prompt);

        [$sent, $cmd] = $chat->update($msg);

        self::assertNotNull($cmd, 'a turn was dispatched');
        self::assertTrue($sent->inFlight);
        self::assertSame('', $sent->inputBuf, 'submitted, not left in the box');
        $users = array_values(array_filter($sent->history, static fn(Message $m): bool => $m->role === Role::User));
        self::assertSame('fix the login bug', $users[0]->content ?? null);
    }

    /**
     * Delivered once: the clone the submit produced carries no prompt, so a
     * host that calls init() again does not send it twice.
     */
    public function testThePromptIsConsumedBySubmitting(): void
    {
        $chat = (new Chat())->withInitialPrompt('once');

        [$sent] = $chat->update(self::initialPromptMsg($chat));

        self::assertNull(self::initialPromptMsgOrNull($sent));
    }

    public function testNoPromptLeavesInitAsItWas(): void
    {
        self::assertNull(self::initialPromptMsgOrNull(new Chat()));
        self::assertNull(self::initialPromptMsgOrNull((new Chat())->withInitialPrompt('   ')));
        self::assertNull(self::initialPromptMsgOrNull((new Chat())->withInitialPrompt(null)));
    }

    /**
     * A slash command on the command line runs as the command it is, exactly
     * as if typed — the prompt goes through submit(), not around it.
     */
    public function testASlashCommandRunsAsACommand(): void
    {
        $chat = (new Chat())->withInitialPrompt('/help');

        [$answered] = $chat->update(self::initialPromptMsg($chat));

        self::assertFalse($answered->inFlight, '/help is not a model turn');
        self::assertSame('', $answered->inputBuf);
    }

    /**
     * Bare `--resume` opens the picker: choosing comes first, so the words
     * wait in the box instead of going to a session the user has not picked.
     */
    public function testWithThePickerOpenThePromptWaitsAsTheDraft(): void
    {
        $dir = sys_get_temp_dir() . '/crush-initial-prompt-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        try {
            $store = new EnhancedSessionStore($dir . '/session.db');
            $store->createSession('s1', 'p', 'm');
            $chat = (new Chat(sessionStore: $store, currentSessionId: 's1'))
                ->withSessionPickerOpen()
                ->withInitialPrompt('fix it');
            self::assertNotNull($chat->sessionPicker(), 'fixture: picker open');

            [$waiting, $cmd] = $chat->update(self::initialPromptMsg($chat));

            self::assertNull($cmd);
            self::assertFalse($waiting->inFlight);
            self::assertSame('fix it', $waiting->inputBuf);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private static function initialPromptMsg(Chat $chat): InitialPromptMsg
    {
        $msg = self::initialPromptMsgOrNull($chat);
        self::assertNotNull($msg, 'init() delivered no InitialPromptMsg');

        return $msg;
    }

    /**
     * The InitialPromptMsg init()'s Cmd produces, unwrapping a batch, or null.
     * These Chats are not the runtime-notice drain owner, so init() carries no
     * promise-backed wake that running the Cmd could arm on the loop.
     */
    private static function initialPromptMsgOrNull(Chat $chat): ?InitialPromptMsg
    {
        $cmd = $chat->init();
        if ($cmd === null) {
            return null;
        }

        $queue = [$cmd];
        while ($queue !== []) {
            $result = array_shift($queue)();
            if ($result instanceof InitialPromptMsg) {
                return $result;
            }
            if ($result instanceof BatchMsg) {
                array_push($queue, ...$result->cmds);
            }
        }

        return null;
    }
}

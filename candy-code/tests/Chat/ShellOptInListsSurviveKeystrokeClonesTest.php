<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Chat;

/**
 * rev-r4 MINOR-2: the two C-SEC-3.b opt-in lists are LAUNCH answers carried
 * through `Chat::mutate()`'s field map. The keystroke that submits a `/name`
 * is always a clone of the Chat the Bootstrap built, so a line dropped from
 * the map would silently widen the posture back to deny-all at the first
 * typed character — the exact failure the `projectCommandsTrusted` carry
 * above it was commented against, pinned here for the two list siblings.
 */
final class ShellOptInListsSurviveKeystrokeClonesTest extends TestCase
{
    public function testATypedCharacterCarriesBothShellOptInListsIntoTheClone(): void
    {
        $chat = new Chat(
            trustedBundleCommands: ['kit'],
            mcpPromptShell: ['srv'],
        );

        // Any real update routes through the mutate() field map — a keystroke
        // into the composer is the production shape this carry exists for.
        [$clone] = $chat->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertNotSame($chat, $clone, 'the update really produced a clone');

        $bundle = (new \ReflectionProperty(Chat::class, 'trustedBundleCommands'))->getValue($clone);
        $shell = (new \ReflectionProperty(Chat::class, 'mcpPromptShell'))->getValue($clone);
        self::assertSame(['kit'], $bundle, 'the bundle allow-list must survive the keystroke clone — dropping it from the mutate() map re-locks opted-in origins');
        self::assertSame(['srv'], $shell, 'the MCP server allow-list must survive the keystroke clone for the same reason');

        // The shipped default travels too: an opted-out session must not gain
        // lists on the way, and an empty carry is still a carry.
        [$plainClone] = (new Chat())->update(new KeyMsg(KeyType::Char, 'h'));
        self::assertSame([], (new \ReflectionProperty(Chat::class, 'trustedBundleCommands'))->getValue($plainClone));
        self::assertSame([], (new \ReflectionProperty(Chat::class, 'mcpPromptShell'))->getValue($plainClone));
    }
}

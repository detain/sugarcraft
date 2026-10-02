<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\App\DockPaneMsg;
use SugarCraft\Crush\App\LayoutResetMsg;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;

/**
 * Audit 15b-24: CommandParser ends a name at ':' as well as at a space, so
 * `/pane:dock left` reaches the `/pane` arm — but `/pane`, `/layout` and
 * `/mcp` split the WHOLE draft on whitespace, so the sub-command stayed glued
 * to the name (`["/pane:dock", "left"]`) and the arm answered with usage.
 * Each colon spelling must now behave exactly like its space spelling.
 *
 * @see Chat::commandTokens()
 */
final class PositionalCommandColonSpellingTest extends TestCase
{
    /** @return array{0: Chat, 1: mixed} the settled Chat and the message its Cmd sends, if any */
    private function enter(string $draft): array
    {
        [$next, $cmd] = (new Chat(inputBuf: $draft))->update(new KeyMsg(KeyType::Enter, ''));

        return [$next, $cmd === null ? null : $cmd()];
    }

    /** The rows after the echo — what the arm answered, as plain text. */
    private static function answer(Chat $chat): array
    {
        return array_map(static fn(Message $m): string => $m->content, array_slice($chat->history, 1));
    }

    /** @return array<string, array{string, string}> */
    public static function colonAndSpaceSpellings(): array
    {
        return [
            'pane dock with a side' => ['/pane:dock left', '/pane dock left'],
            'pane dock with a side and a name' => ['/pane:dock right files', '/pane dock right files'],
            'pane toggle' => ['/pane:toggle', '/pane toggle'],
            'pane toggle with a name' => ['/pane:toggle files', '/pane toggle files'],
            'layout reset' => ['/layout:reset', '/layout reset'],
            'pane usage' => ['/pane:sideways', '/pane sideways'],
            'layout usage' => ['/layout:nonsense', '/layout nonsense'],
        ];
    }

    #[DataProvider('colonAndSpaceSpellings')]
    public function testTheColonSpellingIsTheSpaceSpelling(string $colon, string $space): void
    {
        [$viaColon, $colonMsg] = $this->enter($colon);
        [$viaSpace, $spaceMsg] = $this->enter($space);

        self::assertEquals($spaceMsg, $colonMsg, "{$colon} must send what {$space} sends");
        self::assertSame(self::answer($viaSpace), self::answer($viaColon), "{$colon} must answer what {$space} answers");
        self::assertSame('', $viaColon->inputBuf);
    }

    public function testPaneDockColonSpellingDocksInsteadOfPrintingUsage(): void
    {
        [$chat, $msg] = $this->enter('/pane:dock left');

        self::assertEquals(new DockPaneMsg('left', null), $msg, 'the dock really happens — before the fix the arm read `left` as its verb');
        self::assertSame([], self::answer($chat), 'and no usage notice is written');
    }

    public function testLayoutResetColonSpellingResets(): void
    {
        [, $msg] = $this->enter('/layout:reset');

        self::assertInstanceOf(LayoutResetMsg::class, $msg);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function mcpSpellings(): array
    {
        return [
            'colon list' => ['/mcp:list', ['list']],
            'space list' => ['/mcp list', ['list']],
            'colon remove with a server' => ['/mcp:remove https://example.test/mcp', ['remove', 'https://example.test/mcp']],
            'colon with the auth noun' => ['/mcp:auth list', ['list']],
            'bare legacy form' => ['mcp auth list', ['list']],
            'bare slash name' => ['/mcp', []],
        ];
    }

    /**
     * The `/mcp` arm reaches {@see \SugarCraft\Crush\Commands\McpAuthCommand},
     * which reads the user's auth store; the argv it is handed is the whole of
     * what the spelling decides, so this pins that argv rather than touching a
     * store.
     *
     * @param list<string> $argv
     */
    #[DataProvider('mcpSpellings')]
    public function testEveryMcpSpellingReducesToTheSameArgv(string $draft, array $argv): void
    {
        $parse = new \ReflectionMethod(Chat::class, 'parseMcpArgs');

        self::assertSame($argv, $parse->invoke(null, $draft));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Audit 15b-22: the argument a dispatched command reads is the text after its
 * parsed name and ONE separator, and `/rewind` refuses anything that is not a
 * step count.
 *
 * Both halves were the same defect seen from two sides. CommandParser ends a
 * name at ':' as well as at a space, so `/rename:foo` reaches the `/rename`
 * arm - but the handlers sliced the draft at a fixed offset, and kept the ':'.
 * And `/rewind` cast its argument with `(int)` and clamped to 1, so every
 * non-numeric spelling (`help`, `last`, `-2`, `:all`) RAN a one-step rewind,
 * dropping the latest answer from the live and persisted history.
 *
 * @see Chat::handleRewindCommand()
 * @see Chat::handleRenameCommand()
 * @see Chat::handleThemeCommand()
 */
final class CommandArgumentSeparatorTest extends TestCase
{
    private string $tempDir;
    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/cmd_arg_sep_test_' . uniqid('', true);
        mkdir($this->tempDir, 0755, true);
        $this->store = new EnhancedSessionStore($this->tempDir . '/sessions.db');
        $this->store->createSession('s1', 'openai', 'gpt-4');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    /** @return array<string, array{string}> */
    public static function nonNumericRewindArguments(): array
    {
        return [
            'help word' => ['/rewind help'],
            'last word' => ['/rewind last'],
            'negative' => ['/rewind -2'],
            'zero' => ['/rewind 0'],
            'colon word' => ['/rewind:all'],
            'colon help' => ['/rewind:help'],
            'number then junk' => ['/rewind 2x'],
        ];
    }

    #[DataProvider('nonNumericRewindArguments')]
    public function testANonStepCountPrintsUsageAndRewindsNothing(string $draft): void
    {
        $chat = $this->chatWithCheckpoints($draft);
        $before = $chat->history;

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        // The ONLY change is the echo and the usage reply: had a checkpoint
        // been restored, the transcript would be the checkpoint's two rows.
        $this->assertCount(count($before) + 2, $next->history);
        foreach ($before as $i => $message) {
            $this->assertSame($message->content, $next->history[$i]->content, "row {$i} must survive {$draft}");
        }
        $this->assertSame(Role::User, $next->history[count($before)]->role);
        $this->assertSame($draft, $next->history[count($before)]->content);

        $reply = $next->history[count($before) + 1];
        $this->assertSame(Role::Assistant, $reply->role);
        $this->assertStringStartsWith('Usage: /rewind [n]', $reply->content);
        $this->assertStringNotContainsString('Rewound', $reply->content);
        $this->assertFalse($next->inFlight);
    }

    /** @return array<string, array{string, int}> */
    public static function stepCountSpellings(): array
    {
        // [draft, rows the restored checkpoint holds]: one step back is the
        // newer three-row checkpoint, two steps back the older two-row one.
        return [
            'bare' => ['/rewind', 3],
            'space' => ['/rewind 1', 3],
            'colon' => ['/rewind:1', 3],
            'colon then space' => ['/rewind: 1', 3],
            'space two' => ['/rewind 2', 2],
            'colon two' => ['/rewind:2', 2],
        ];
    }

    #[DataProvider('stepCountSpellings')]
    public function testAStepCountStillRewinds(string $draft, int $restoredRows): void
    {
        [$next] = $this->chatWithCheckpoints($draft)->update(new KeyMsg(KeyType::Enter, ''));

        $reply = $next->history[count($next->history) - 1];
        $this->assertStringStartsWith('Rewound', $reply->content);
        // The checkpoint's rows plus the echo and the reply.
        $this->assertCount($restoredRows + 2, $next->history);
        $this->assertSame('Hello', $next->history[0]->content);
        $this->assertSame('Hi there!', $next->history[1]->content);
        $this->assertSame($draft, $next->history[$restoredRows]->content);
    }

    public function testColonSpelledRenameStoresTheNameWithoutTheColon(): void
    {
        [$next] = $this->sessionChat('/rename:Release prep')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('Release prep', $this->store->getSession('s1')['name'] ?? null);
        $this->assertStringContainsString("Session renamed to 'Release prep'", $next->history[count($next->history) - 1]->content);
    }

    public function testSpaceSpelledRenameIsUnchanged(): void
    {
        $this->sessionChat('/rename Release prep')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame('Release prep', $this->store->getSession('s1')['name'] ?? null);

        // One separator is consumed, not every ':' - a space-spelled name that
        // begins with a colon is the user's name, verbatim.
        $this->sessionChat('/rename :colon-first')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(':colon-first', $this->store->getSession('s1')['name'] ?? null);
    }

    /** @return array<string, array{string}> */
    public static function themeSpellings(): array
    {
        return [
            'space' => ['/theme dracula'],
            'colon' => ['/theme:dracula'],
        ];
    }

    #[DataProvider('themeSpellings')]
    public function testColonSpelledThemeBehavesLikeTheSpaceSpelling(string $draft): void
    {
        $written = [];
        $chat = (new Chat(inputBuf: $draft, themeName: 'dark'))
            ->withOnConfigChange(function (string $k, string $v) use (&$written): void {
                $written[$k] = $v;
            });

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('dracula', $next->theme()->name);
        $this->assertSame(['theme' => 'dracula'], $written);
        $this->assertSame("Theme set to 'dracula'.", $next->history[count($next->history) - 1]->content);
    }

    private function sessionChat(string $draft): Chat
    {
        return new Chat(
            history: [],
            inputBuf: $draft,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 's1',
        );
    }

    /**
     * A four-row transcript over two saved checkpoints of two and three rows -
     * so a performed rewind is visible as the transcript shrinking.
     */
    private function chatWithCheckpoints(string $draft): Chat
    {
        $this->store->saveCheckpoint('s1', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
            'inputBuf' => '',
        ]);
        $this->store->saveCheckpoint('s1', [
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
                ['role' => 'user', 'content' => 'Tell me more'],
            ],
            'inputBuf' => '',
        ]);

        return new Chat(
            history: [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
                Message::user('Tell me more'),
                Message::assistant('More info'),
            ],
            inputBuf: $draft,
            backend: new EchoBackend(),
            sessionStore: $this->store,
            currentSessionId: 's1',
        );
    }
}

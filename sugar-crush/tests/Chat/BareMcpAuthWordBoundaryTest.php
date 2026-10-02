<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

use function React\Promise\resolve;

/**
 * Audit 15b-11: the leading-slash-less `mcp auth …` spelling is claimed only
 * when `mcp` and `auth` are WHOLE words.
 *
 * The matcher used to be `str_starts_with($text, 'mcp auth')`, so prose such as
 * "mcp authentication keeps failing on my server, why?" was captured by the MCP
 * handler — idle, the model was never asked and the transcript showed
 * "Unknown sub-command 'authentication'"; mid-turn, the draft was refused as a
 * command instead of being queued. Both sites are driven here through the real
 * `update(KeyMsg Enter)` entry point, and the backend is a recorder so "went to
 * the model" is a count of calls, not an inference from `inFlight`.
 */
final class BareMcpAuthWordBoundaryTest extends TestCase
{
    use HomeSandboxTrait;

    private const MCP_AUTH_LOOKALIKE_PROSE = 'mcp authentication keeps failing on my server, why?';

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-mcpauth-wb-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            self::removeMcpAuthSandbox($this->sandbox);
        }
    }

    /** Removed in-process: no child, so no stderr for the suite to inherit. */
    private static function removeMcpAuthSandbox(string $dir): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($dir);
    }

    /** A backend that records every turn it is asked for and answers at once. */
    private function recordingBackend(): Backend
    {
        return new class () implements Backend {
            /** @var list<list<Message>> */
            public array $calls = [];

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls[] = $history;

                return Message::assistant('model-reply');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls[] = $history;

                return resolve(Message::assistant('model-reply'));
            }
        };
    }

    /**
     * Run $cmd far enough that any backend call it carries is made: the turn's
     * completeAsync() lives inside the returned closure, possibly in a batch.
     */
    private function runCommandTree(?\Closure $cmd): void
    {
        if ($cmd === null) {
            return;
        }
        $msg = $cmd();
        if ($msg instanceof BatchMsg) {
            foreach ($msg->cmds as $child) {
                $this->runCommandTree($child);
            }
        }
    }

    private function draftedChat(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    public function testIdleProseThatMerelyStartsWithMcpAuthGoesToTheModel(): void
    {
        $backend = $this->recordingBackend();
        [$next, $cmd] = (new Chat(inputBuf: self::MCP_AUTH_LOOKALIKE_PROSE, backend: $backend))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->runCommandTree($cmd);

        $this->assertCount(1, $backend->calls, 'the prose must be sent to the model exactly once');
        $this->assertTrue($next->inFlight, 'and a turn is running for it');
        foreach ($next->history as $message) {
            $this->assertStringNotContainsString('Unknown sub-command', $message->content, 'the MCP handler must not answer prose');
        }
        $last = $backend->calls[0][count($backend->calls[0]) - 1];
        $this->assertSame(Role::User, $last->role);
        $this->assertStringContainsString(self::MCP_AUTH_LOOKALIKE_PROSE, $last->content, 'the model is asked the user\'s actual question');
    }

    public function testMidTurnProseThatMerelyStartsWithMcpAuthIsQueuedNotRefused(): void
    {
        [$chat] = (new Chat(inputBuf: 'the first thing', backend: $this->recordingBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');
        $before = count($chat->history);

        [$after] = $this->draftedChat($chat, self::MCP_AUTH_LOOKALIKE_PROSE)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([self::MCP_AUTH_LOOKALIKE_PROSE], $after->queuedPrompts(), 'prose is queued for the next turn');
        $this->assertSame('', $after->inputBuf, 'and the box is cleared like any queued prompt');
        $this->assertCount($before + 1, $after->history, 'exactly one notice is written');
        $notice = $after->history[$before];
        $this->assertSame(Role::System, $notice->role);
        $this->assertStringStartsWith('Queued (1 waiting)', $notice->content, 'and it is the queue notice');
        $this->assertStringNotContainsString('is a command', $notice->content, 'not the command refusal');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commandSpellings(): iterable
    {
        yield 'mcp auth list' => ['mcp auth list'];
        yield 'bare mcp auth' => ['mcp auth'];
        yield 'extra whitespace' => ["mcp  auth\tlist"];
    }

    /**
     * The real spellings still reach the handler — the word boundary must not
     * over-correct. The extra-whitespace case is accepted because the handler's
     * own tokeniser splits on any whitespace run, so it parses to the same argv.
     *
     * @dataProvider commandSpellings
     */
    public function testRealMcpAuthSpellingsStillRouteToTheHandlerIdle(string $draft): void
    {
        $backend = $this->recordingBackend();
        [$next, $cmd] = (new Chat(inputBuf: $draft, backend: $backend))
            ->update(new KeyMsg(KeyType::Enter, ''));
        $this->runCommandTree($cmd);

        $this->assertSame([], $backend->calls, 'a command never reaches the model');
        $this->assertFalse($next->inFlight);
        $this->assertCount(2, $next->history, 'the user echo plus the handler reply');
        $this->assertSame(Role::Assistant, $next->history[1]->role);
        $this->assertStringContainsString('MCP', $next->history[1]->content, 'the MCP handler answered');
        $this->assertStringNotContainsString('Unknown sub-command', $next->history[1]->content, 'and parsed the draft as `list` (or no action)');
    }

    /**
     * @dataProvider commandSpellings
     */
    public function testRealMcpAuthSpellingsAreStillRefusedMidTurn(string $draft): void
    {
        [$chat] = (new Chat(inputBuf: 'the first thing', backend: $this->recordingBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');

        [$after] = $this->draftedChat($chat, $draft)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame([], $after->queuedPrompts(), 'a command is not queued as prose');
        $this->assertSame($draft, $after->inputBuf, 'the refused draft is kept');
    }
}

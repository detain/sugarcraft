<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\SystemClipboard;

/**
 * The `/copy [n]` dispatch legs (C-TUI-3.a, GAP-UI-16): every answer is an
 * uiOnly transcript notice, and a successful selection raises the SAME two
 * clipboard legs the drag-release rides — the OSC 52 write relayed through
 * the widget seam batched with a {@see SystemClipboard} copy — while every
 * refusal raises no command at all. Which text the selection is stays the
 * pure {@see \SugarCraft\Code\Commands\CopyCommand}'s own test; here the
 * numbers are unambiguous: the blocky reply below holds exactly two blocks
 * and `/copy 1` must carry the newest one, `second`.
 *
 * Driven as real submitted drafts — a Chat seeded through the constructor
 * with the draft in `inputBuf` (the SlashDispatchTest house shape), Enter
 * through `update()`, never by reaching for the private handler.
 *
 * The clipboard stubs mirror {@see MouseTextSelectionTest}: CI is headless,
 * so discovery is stubbed alongside the runner seam and the argv is pinned
 * as-passed.
 */
final class CopySlashCommandTest extends TestCase
{
    private const OPEN = '```php';
    private const CLOSE = '```';

    private const STUB_CANDIDATE = ['/usr/bin/tmux', 'load-buffer', '-w', '-'];

    /** @var list<array{0:list<string>,1:string}> */
    private array $nativeCopies = [];

    protected function setUp(): void
    {
        $this->nativeCopies = [];
        SystemClipboard::useCandidatesForTesting([self::STUB_CANDIDATE]);
        SystemClipboard::useRunnerForTesting(function (array $argv, string $text): bool {
            $this->nativeCopies[] = [$argv, $text];

            return true;
        });
    }

    protected function tearDown(): void
    {
        SystemClipboard::useRunnerForTesting(static fn (): bool => false);
        SystemClipboard::useCandidatesForTesting(null);
    }

    public function testBareCopyCopiesTheWholeLastReplyAndSaysSo(): void
    {
        $reply = $this->blockyReply();
        [$next, $cmd] = $this->submit($reply, '/copy');

        self::assertSame([Ansi::setClipboard($reply)], $this->rawWrites($cmd));
        self::assertSame([[self::STUB_CANDIDATE, $reply]], $this->nativeCopies, 'the SystemClipboard leg rides the same text');
        $this->assertNotice($next, [$reply], Lang::t('chat.copy.copied', ['chars' => mb_strlen($reply, 'UTF-8')]));
    }

    public function testNumberCopiesTheNewestBlockOnly(): void
    {
        $reply = $this->blockyReply();
        [$next, $cmd] = $this->submit($reply, '/copy 1');

        // 1 from the end is the newest block.
        self::assertSame([Ansi::setClipboard('second')], $this->rawWrites($cmd));
        self::assertSame([[self::STUB_CANDIDATE, 'second']], $this->nativeCopies);
        $this->assertNotice($next, [$reply], Lang::t('chat.copy.copied', ['chars' => 6]));
    }

    public function testARefusalAnswersInWordsAndTouchesNoClipboard(): void
    {
        $cases = [
            '/copy 9' => Lang::t('chat.copy.out_of_range', ['blocks' => 2, 'n' => '9']),
            '/copy x' => Lang::t('chat.copy.bad_arg', ['arg' => 'x']),
            '/copy 0' => Lang::t('chat.copy.bad_arg', ['arg' => '0']),
        ];

        foreach ($cases as $draft => $expected) {
            $this->nativeCopies = [];
            [$next, $cmd] = $this->submit($this->blockyReply(), $draft);

            self::assertSame([], $this->rawWrites($cmd), "{$draft} must raise no write");
            self::assertSame([], $this->nativeCopies, "{$draft} must not reach the clipboard tool");
            $this->assertNotice($next, [$this->blockyReply()], $expected);
        }
    }

    public function testEmptySessionAndBlocklessReplySayWhich(): void
    {
        [$next, $cmd] = $this->submitDraft([], '/copy');
        self::assertSame([], $this->rawWrites($cmd));
        $this->assertNoticeOn($next, [], Lang::t('chat.copy.no_reply'));

        [$next, $cmd] = $this->submitDraft(['prose only'], '/copy 1');
        self::assertSame([], $this->rawWrites($cmd));
        $this->assertNoticeOn($next, ['prose only'], Lang::t('chat.copy.no_block'));
    }

    /**
     * The notice is uiOnly: it appears in the transcript and not to the
     * model. Asserted on the appended row's own flag.
     */
    public function testTheAnswerRowIsUiOnly(): void
    {
        [$next] = $this->submit($this->blockyReply(), '/copy');

        $added = array_slice($next->history, 2);
        self::assertCount(1, $added);
        self::assertTrue($added[0]->uiOnly, 'the copy notice must not be shown to the model');
    }

    /**
     * The clipboard is an external sink and the transcript is
     * untrusted-by-origin, so `/copy` runs the SAME sweep pair `/export`
     * uses (stripPrivateUse + untrustedForDisplay) once before BOTH legs
     * (C-TUI-3.a review MAJOR). A reply carrying a forged OSC-8 link and
     * the U+E000/U+E001 zone sentinels must reach the terminal write and
     * the clipboard tool's argv as ONLY the stripped bytes — deleting the
     * sanitizer reddens both byte-exact legs here.
     */
    public function testHostileBytesAreStrippedBeforeBothClipboardLegs(): void
    {
        // \x1b]8;;..\x07 is a whole OSC-8 hyperlink; \xEE\x80\x80 /
        // \xEE\x80\x81 are U+E000/U+E001, the Mark::zone sentinels.
        $hostile = "intro line\n\x1b]8;;http://evil.example\x07LINKED\x1b]8;;\x07 tail\n\xEE\x80\x80marker\xEE\x80\x81 end\noutro";
        $clean = "intro line\nLINKED tail\nmarker end\noutro";
        [$next, $cmd] = $this->submit($hostile, '/copy');

        self::assertSame([Ansi::setClipboard($clean)], $this->rawWrites($cmd), 'the OSC 52 leg carries only the stripped bytes');
        self::assertSame([[self::STUB_CANDIDATE, $clean]], $this->nativeCopies, 'the SystemClipboard leg carries only the stripped bytes');
        $this->assertNotice($next, [$hostile], Lang::t('chat.copy.copied', ['chars' => mb_strlen($clean, 'UTF-8')]));
    }

    /**
     * The fence-block path shares the one sanitizer: an OSC-8 link forged
     * INSIDE a code block cannot ride `/copy 1` out either.
     */
    public function testAHostileFenceBlockIsStrippedToo(): void
    {
        $reply = "prose\n" . self::OPEN . "\nfirst \xEE\x80\x80inside\xEE\x80\x81\n" . self::CLOSE;
        [$next, $cmd] = $this->submit($reply, '/copy 1');

        self::assertSame([Ansi::setClipboard('first inside')], $this->rawWrites($cmd));
        self::assertSame([[self::STUB_CANDIDATE, 'first inside']], $this->nativeCopies);
        $this->assertNotice($next, [$reply], Lang::t('chat.copy.copied', ['chars' => 12]));
    }

    // ---- harness --------------------------------------------------------------

    /**
     * @param list<string> $assistantBodies oldest first (the reply is the last)
     *
     * @return list<Message>
     */
    private function fixture(array $assistantBodies): array
    {
        $history = [];
        foreach ($assistantBodies as $body) {
            $history[] = Message::user('ask');
            $history[] = Message::assistant($body);
        }

        return $history;
    }

    /**
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function submit(string $reply, string $draft): array
    {
        return $this->submitDraft([$reply], $draft);
    }

    /**
     * @param list<string> $assistantBodies
     *
     * @return array{0: Chat, 1: ?\Closure}
     */
    private function submitDraft(array $assistantBodies, string $draft): array
    {
        $chat = (new Chat(
            history: $this->fixture($assistantBodies),
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(100, 30);

        return $chat->update(new KeyMsg(KeyType::Enter));
    }

    private function blockyReply(): string
    {
        return implode("\n", [
            'intro line',
            self::OPEN,
            'first',
            self::CLOSE,
            'between the blocks',
            self::OPEN,
            'second',
            self::CLOSE,
            'outro line',
        ]);
    }

    /**
     * The fixture for $assistantBodies is known-length: 2 rows per reply, so
     * everything past it is the command's one answer row.
     *
     * @param list<string> $assistantBodies
     */
    private function assertNotice(Chat $next, array $assistantBodies, string $expected): void
    {
        $this->assertNoticeOn($next, $assistantBodies, $expected);
    }

    /**
     * @param list<string> $assistantBodies
     */
    private function assertNoticeOn(Chat $next, array $assistantBodies, string $expected): void
    {
        $added = array_slice($next->history, 2 * count($assistantBodies));
        self::assertSame([$expected], array_map(static fn(Message $m): string => $m->content, $added));
        self::assertSame('', $next->draft()[0], 'the verb consumed its own draft');
    }

    /**
     * @return list<string> every byte-write the command raises, in order
     */
    private function rawWrites(?\Closure $cmd): array
    {
        if ($cmd === null) {
            return [];
        }

        $msg = $cmd();
        if ($msg instanceof RawMsg) {
            return [$msg->bytes];
        }
        if ($msg instanceof BatchMsg) {
            $writes = [];
            foreach ($msg->cmds as $inner) {
                $writes = [...$writes, ...$this->rawWrites($inner)];
            }

            return $writes;
        }

        return [];
    }
}

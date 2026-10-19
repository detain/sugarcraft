<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\Attachments\MentionIndex;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Renderer;

/**
 * TUI-1 (plan_comparison): the fuzzy `@` file popup — open, browse, accept,
 * dismiss — driven through the same public key surface the terminal uses, on
 * a real `git init` root (the candidate list is git's own listing).
 *
 * Unique-prefix completion for the same token lives in the pre-existing
 * mention tests and the fallback pins here: it must keep working exactly
 * where the popup does not show (cold index, dismissed popup, non-git root).
 */
final class MentionFilePopupTest extends TestCase
{
    private string $w2bRoot;

    protected function setUp(): void
    {
        MentionIndex::forget();
        $this->w2bRoot = \sys_get_temp_dir() . '/w2b-popup-' . uniqid('p', true);
        mkdir($this->w2bRoot . '/src', 0777, true);
        exec('git init --quiet ' . escapeshellarg($this->w2bRoot) . ' 2>&1');
        foreach (['src/Chat.php', 'src/Renderer.php', 'src/input_parser.php', 'index.php'] as $rel) {
            file_put_contents($this->w2bRoot . '/' . $rel, '');
        }
    }

    protected function tearDown(): void
    {
        MentionIndex::forget();
        exec('rm -rf ' . escapeshellarg($this->w2bRoot));
    }

    public function testATypedAtOpensThePopupOverTheProject(): void
    {
        $chat = $this->composingAt('@');

        $this->assertTrue($chat->mentionPopupOpen());
        $this->assertSame(
            ['index.php', 'src/Chat.php', 'src/Renderer.php', 'src/input_parser.php'],
            array_column($chat->mentionPopupRows(), 'path'),
            'a bare @ heads the sorted listing',
        );
    }

    public function testThePopupFiltersAsThePartialGrows(): void
    {
        $chat = $this->composingAt('@src');

        $this->assertTrue($chat->mentionPopupOpen());
        $this->assertSame(
            ['src/Chat.php', 'src/Renderer.php', 'src/input_parser.php'],
            array_column($chat->mentionPopupRows(), 'path'),
        );
    }

    public function testEnterAcceptsTheHighlightedRowInsteadOfSubmitting(): void
    {
        $chat = $this->composingAt('@cha');

        [$next] = $chat->update(new KeyMsg(KeyType::Enter));

        $this->assertSame('@src/Chat.php ', $next->inputBuf, 'accepted with the closing space, and no turn was submitted');
        $this->assertFalse($next->mentionPopupOpen());
    }

    public function testDownThenTabAcceptsTheSelectedRow(): void
    {
        $chat = $this->composingAt('@src/');
        $this->assertSame(0, $chat->mentionMenuIndex());

        [$down] = $chat->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->mentionMenuIndex());

        [$tab] = $down->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('@src/Renderer.php ', $tab->inputBuf);
    }

    public function testTheSelectionWrapsAndResetsWithTheTypedPartial(): void
    {
        [$down] = $this->composingAt('@src/')->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $down->mentionMenuIndex());

        [$retyped] = $down->update(new KeyMsg(KeyType::Char, 'C'));
        $this->assertSame('@src/C', $retyped->inputBuf);
        $this->assertSame(0, $retyped->mentionMenuIndex(), 'a changed list re-highlights the top row, as the slash popup does');
    }

    public function testEscapeClosesThePopupBeforeTheStopBindingArms(): void
    {
        $chat = $this->composingAt('@src');

        [$closed] = $chat->update(new KeyMsg(KeyType::Escape));

        $this->assertFalse($closed->mentionPopupOpen(), 'the popup ate the keystroke');
        $this->assertNull($this->readEscapeStamp($closed), 'and the two-beat stop arm did NOT fire');

        [$retyped] = $closed->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertTrue($retyped->mentionPopupOpen(), 'typing reopens it');
    }

    public function testAPrefixCompletionStillRunsBehindADismissedPopup(): void
    {
        [$closed] = $this->composingAt('@src/Cha')->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($closed->mentionPopupOpen());

        [$tab] = $closed->update(new KeyMsg(KeyType::Tab));

        $this->assertSame('@src/Chat.php ', $tab->inputBuf, 'the cold fallback — FileMentions unique-prefix — still completes');
    }

    public function testANonGitRootNeverOpensThePopupAndLeavesTabToThePrefixPath(): void
    {
        $stray = $this->w2bRoot . '-stray';
        mkdir($stray, 0777, true);
        file_put_contents($stray . '/loose.txt', '');
        $chat = (new Chat(inputBuf: '@loose', backend: new EchoBackend(), projectRoot: $stray))->withSize(100, 30);

        $this->assertFalse($chat->mentionPopupOpen());
        $this->assertTrue($chat->mentionOwnsTab(), 'Tab is still the mention key');

        [$tab] = $chat->update(new KeyMsg(KeyType::Tab));
        $this->assertSame('@loose.txt ', $tab->inputBuf, 'prefix completion works with no index at all');

        exec('rm -rf ' . escapeshellarg($stray));
    }

    public function testADraftWithoutAnAtTokenHasNoPopup(): void
    {
        $plain = (new Chat(inputBuf: 'fix the bug', backend: new EchoBackend(), projectRoot: $this->w2bRoot))->withSize(100, 30);
        $this->assertFalse($plain->mentionPopupOpen());

        $midWord = (new Chat(inputBuf: 'mail me@x', backend: new EchoBackend(), projectRoot: $this->w2bRoot))->withSize(100, 30);
        $this->assertFalse($midWord->mentionPopupOpen(), 'an @ glued to a word is not a mention — tokenAt already knew, the popup just inherits it');
    }

    public function testAClippedRowKeepsOnlyTheHighlightIndicesItCanStillShow(): void
    {
        // rev-w2b MINOR-1: rank's indices address the UNCLIPPED subject, and
        // Highlighter's bounds-drop is one cell too generous on a cut row —
        // clipToWidth's trailing `…` occupies an in-bounds haystack slot, so
        // an index at exactly the cut length would style the ellipsis (a
        // highlight overflowing the row's truncation). The re-clip drops it.
        $clip = new \ReflectionMethod(Renderer::class, 'clipHighlightIndices');
        $clip->setAccessible(true);

        $this->assertSame(
            [0, 2, 4],
            $clip->invoke(null, [0, 2, 4], 'parser.php', 'parser.php'),
            'a row that never clipped keeps every index',
        );
        $this->assertSame(
            [0],
            $clip->invoke(null, [0, 7, 8, 20], 'parsearchatlibrary.php', 'parse…'),
            'index 7 is the `…` slot and 8/20 are gone characters — only 0 survives',
        );
        $this->assertSame([], $clip->invoke(null, [9], 'abcdefgh', '…'), 'a fully eaten row keeps nothing');
    }

    private function composingAt(string $draft): Chat
    {
        return (new Chat(inputBuf: $draft, backend: new EchoBackend(), projectRoot: $this->w2bRoot))->withSize(100, 30);
    }

    private function readEscapeStamp(Chat $chat): ?object
    {
        $property = new \ReflectionProperty(Chat::class, 'lastEscapeAt');
        $property->setAccessible(true);

        return $property->getValue($chat);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Comment;
use SugarCraft\Dash\Grid\Avatar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CommentTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCommentImplementsSizer(): void
    {
        $comment = Comment::create('Author', 'Body');
        $this->assertInstanceOf(Sizer::class, $comment);
    }

    public function testCommentImplementsItem(): void
    {
        $comment = Comment::create('Author', 'Body');
        $this->assertInstanceOf(Item::class, $comment);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $comment = Comment::create('Author', 'Body');
        $rendered = $comment->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsAuthor(): void
    {
        $comment = Comment::create('John Doe', 'This is a comment');
        $rendered = $comment->render();

        $this->assertStringContainsString('John Doe', $rendered);
    }

    public function testRenderContainsBody(): void
    {
        $comment = Comment::create('Author', 'This is the comment body');
        $rendered = $comment->render();

        $this->assertStringContainsString('This is the comment body', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Reply comments
    // ═══════════════════════════════════════════════════════════════

    public function testReplyFactory(): void
    {
        $comment = Comment::reply('Reply Author', 'This is a reply');
        $rendered = $comment->render();

        $this->assertStringContainsString('Reply Author', $rendered);
        $this->assertStringContainsString('This is a reply', $rendered);
    }

    public function testWithIsReply(): void
    {
        $comment = Comment::create('Author', 'Body')->withIsReply(true);
        $rendered = $comment->render();

        $this->assertStringContainsString('Author', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Timestamp
    // ═══════════════════════════════════════════════════════════════

    public function testTimestamp(): void
    {
        $comment = Comment::create('Author', 'Body')
            ->withTimestamp('2 hours ago');
        $rendered = $comment->render();

        $this->assertStringContainsString('2 hours ago', $rendered);
    }

    public function testNullTimestampNotShown(): void
    {
        $comment = Comment::create('Author', 'Body');
        $rendered = $comment->render();

        $this->assertStringNotContainsString('·', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edited indicator
    // ═══════════════════════════════════════════════════════════════

    public function testEditedIndicator(): void
    {
        $comment = Comment::create('Author', 'Body')->withIsEdited(true);
        $rendered = $comment->render();

        $this->assertStringContainsString('(edited)', $rendered);
    }

    public function testNotEditedWithoutIndicator(): void
    {
        $comment = Comment::create('Author', 'Body')->withIsEdited(false);
        $rendered = $comment->render();

        $this->assertStringNotContainsString('(edited)', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $comment = Comment::create('Author', 'Body')
            ->withHeaderColor(Color::ansi(9));
        $rendered = $comment->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Avatar
    // ═══════════════════════════════════════════════════════════════

    public function testWithAvatar(): void
    {
        $avatar = Avatar::small('John');
        $comment = Comment::create('Author', 'Body')->withAvatar($avatar);
        $rendered = $comment->render();

        $this->assertStringContainsString('Author', $rendered);
        // Avatar would be rendered as part of the comment if integrated
    }

    public function testWithNullAvatar(): void
    {
        $comment = Comment::create('Author', 'Body')->withAvatar(null);
        $rendered = $comment->render();

        $this->assertStringContainsString('Author', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Comment::create('Author', 'Body');
        $resized = $original->setSize(80, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocationAffectsWordWrap(): void
    {
        $narrow = Comment::create('Author', 'This is a very long comment body that should be wrapped')
            ->setSize(30, 10);
        $wide = Comment::create('Author', 'This is a very long comment body that should be wrapped')
            ->setSize(80, 10);

        $narrowRendered = $narrow->render();
        $wideRendered = $wide->render();
        $narrowLines = explode("\n", $narrowRendered);
        $wideLines = explode("\n", $wideRendered);

        // Narrow comment should have more lines due to wrapping
        $this->assertGreaterThanOrEqual(count($wideLines), count($narrowLines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithAuthorReturnsNewInstance(): void
    {
        $original = Comment::create('Original', 'Body');
        $updated = $original->withAuthor('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithBodyReturnsNewInstance(): void
    {
        $original = Comment::create('Author', 'Original');
        $updated = $original->withBody('Updated');

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('Updated', $updated->render());
    }

    public function testWithTimestampReturnsNewInstance(): void
    {
        $original = Comment::create('Author', 'Body');
        $updated = $original->withTimestamp('1 hour ago');

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithAuthor(): void
    {
        $original = Comment::create('Original', 'Body');
        $original->withAuthor('Changed');
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $comment = Comment::create('Author', 'Short body');
        [$w, $h] = $comment->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThanOrEqual(1, $h);
    }

    public function testGetInnerSizeWithLongBody(): void
    {
        $longBody = str_repeat('word ', 100);
        $comment = Comment::create('Author', $longBody)->setSize(40, 50);
        [$w, $h] = $comment->getInnerSize();

        $this->assertSame(40, $w);
        $this->assertGreaterThan(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyBody(): void
    {
        $comment = Comment::create('Author', '');
        $rendered = $comment->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('Author', $rendered);
    }

    public function testEmptyAuthor(): void
    {
        $comment = Comment::create('', 'Body');
        $rendered = $comment->render();

        $this->assertStringContainsString('Body', $rendered);
    }

    public function testUnicodeInAuthor(): void
    {
        $comment = Comment::create('日本語作者', 'Body');
        $rendered = $comment->render();

        $this->assertStringContainsString('日本語作者', $rendered);
    }

    public function testUnicodeInBody(): void
    {
        $comment = Comment::create('Author', 'これはテストコメントです');
        $rendered = $comment->render();

        $this->assertStringContainsString('これはテストコメントです', $rendered);
    }

    public function testSpecialCharsInBody(): void
    {
        $comment = Comment::create('Author', 'Test & <special> "chars"');
        $rendered = $comment->render();

        $this->assertStringContainsString('Test & <special> "chars"', $rendered);
    }
}

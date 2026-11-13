<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\Boxer\Boxer;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Layout\Boxer\SizeError;

/**
 * Finding 001 arm pins.
 *
 * Node::renderVertical()/renderHorizontal() return `new SizeError(...)`
 * (and wrapError() results) from several arms while declaring the union
 * `array|string` — under strict_types every one of those arms raised a
 * TypeError instead of producing the error object renderTree() already
 * declares and Boxer::render() already sanitizes. Each SizeError-return
 * site now carries a behavioral pin: reach the arm, assert the arm
 * RETURNS the error object (pre-fix these runs fatal with a TypeError).
 */
final class NodeRenderErrorArmTest extends TestCase
{
    private static function item(string $rendered): Item
    {
        return new class ($rendered) implements Item {
            public function __construct(private readonly string $rendered)
            {
            }

            public function render(): string
            {
                return $this->rendered;
            }
        };
    }

    public function testHorizontalWithNoChildrenReturnsSizeErrorNotTypeError(): void
    {
        $result = Node::horizontal()->renderTree([], 10, 10);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringContainsString('no children to render', $result->getMessage());
    }

    public function testVerticalWithNoChildrenReturnsSizeErrorNotTypeError(): void
    {
        $result = Node::vertical()->renderTree([], 10, 10);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringContainsString('no children to render', $result->getMessage());
    }

    public function testHorizontalWithMismatchedChildHeightsReturnsSizeError(): void
    {
        $root = Node::horizontal(
            Node::leaf('0')->setSize(5, 3),
            Node::leaf('1')->setSize(5, 4),
        );

        // Child 0 must render successfully first — the loop checks the
        // next child's height only after serving the current one.
        $result = $root->renderTree(['0' => self::item('ab')], 10, 4);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringContainsString('same height when horizontal', $result->getMessage());
    }

    public function testVerticalWithMismatchedChildWidthsReturnsSizeError(): void
    {
        $root = Node::vertical(
            Node::leaf('0')->setSize(5, 2),
            Node::leaf('1')->setSize(6, 2),
        );

        $result = $root->renderTree(['0' => self::item('ab')], 6, 4);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringContainsString('same width when vertical', $result->getMessage());
    }

    public function testHorizontalJoinRejectsLineWiderThanAllocatedChild(): void
    {
        // Child A is sized 8 wide by the parent join but wraps a 20-wide
        // inner child — its returned lines pass A's own check and fail
        // the parent's width budget (the str_repeat-underflow arm).
        $inner = Node::leaf('0')->setSize(20, 2);
        $root = Node::horizontal(
            Node::horizontal($inner)->setSize(8, 2),
            Node::leaf('1')->setSize(2, 2),
        );

        $result = $root->renderTree(
            ['0' => self::item(str_repeat('X', 20)), '1' => self::item('ab')],
            10,
            2
        );

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringContainsString('too wide line', $result->getMessage());
    }

    public function testHorizontalWrapErrorChannelCarriesChildIndex(): void
    {
        $root = Node::horizontal(
            Node::horizontal()->setSize(5, 2), // empty branch: fails deep
            Node::leaf('1')->setSize(5, 2),
        );

        $result = $root->renderTree([], 10, 2);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringStartsWith('while rendering the 0 child of a horizontal node:', $result->getMessage());
    }

    public function testVerticalWrapErrorChannelCarriesChildIndex(): void
    {
        $root = Node::vertical(
            Node::horizontal()->setSize(5, 3), // empty branch: fails deep
            Node::leaf('1')->setSize(5, 3),
        );

        $result = $root->renderTree([], 5, 6);

        $this->assertInstanceOf(SizeError::class, $result);
        $this->assertStringStartsWith('while rendering the 0 child of a vertical node:', $result->getMessage());
    }

    public function testBoxerRenderTurnsBranchArmIntoSanitizedString(): void
    {
        // End-to-end consumer proof: an over-wide model line surfaces as
        // the sanitized 'size error:' string — never as a TypeError.
        $root = Node::horizontal(
            Node::leaf('0'),
            Node::leaf('1'),
        );
        $boxer = Boxer::tree($root, [
            '0' => self::item(str_repeat('X', 12)),
            '1' => self::item('ok'),
        ])->setSize(10, 2);

        $rendered = $boxer->render();

        $this->assertIsString($rendered);
        $this->assertStringStartsWith('size error: ', $rendered);
    }
}

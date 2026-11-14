<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\Boxer\Boxer;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Layout\Boxer\NotFoundError;
use SugarCraft\Dashboard\Layout\Boxer\PlaceholderItem;
use SugarCraft\Dashboard\Layout\Boxer\SizeError;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\State\Persistence;

final class BoxerTest extends TestCase
{
    public function testLeafFactory(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'content'; }
        };
        $boxer = Boxer::leaf('0', $item);

        $this->assertInstanceOf(Boxer::class, $boxer);
        $this->assertInstanceOf(Sizer::class, $boxer);
    }

    /**
     * D-133 took the annotation's delete route: Address and SizeFunc
     * were VOs no production door ever consumed (the boxer speaks dotted
     * strings end to end and size functions are plain Closures). The
     * acceptance bar is zero dead classes behind these names.
     */
    public function testRetiredAddressAndSizeFuncLeaveNoDeadClasses(): void
    {
        foreach ([
            'SugarCraft\\Dashboard\\Layout\\Boxer\\Address',
            'SugarCraft\\Dashboard\\Layout\\Boxer\\SizeFunc',
        ] as $gone) {
            // autoload disabled: a missing-file walk emits include()
            // warnings; absence from the already-loaded set is the pin.
            $this->assertFalse(class_exists($gone, false), $gone . ' must not ship');
        }

        $boxerSrc = dirname(__DIR__, 3) . '/src/Layout/Boxer';
        $this->assertFileDoesNotExist($boxerSrc . '/Address.php');
        $this->assertFileDoesNotExist($boxerSrc . '/SizeFunc.php');
    }

    public function testLeafFactoryEmptyAddressThrows(): void    {
        $item = new class implements Item {
            public function render(): string { return 'content'; }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Address must not be empty');

        Boxer::leaf('', $item);
    }

    public function testTreeFactory(): void
    {
        $node = Node::vertical(Node::leaf('0'), Node::leaf('1'));
        $boxer = Boxer::tree($node);

        $this->assertInstanceOf(Boxer::class, $boxer);
    }

    public function testHorizontalFactory(): void
    {
        $boxer = Boxer::horizontal(Node::leaf('0'), Node::leaf('1'));

        $this->assertInstanceOf(Boxer::class, $boxer);
    }

    public function testVerticalFactory(): void
    {
        $boxer = Boxer::vertical(Node::leaf('0'), Node::leaf('1'));

        $this->assertInstanceOf(Boxer::class, $boxer);
    }

    public function testGetRoot(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $this->assertInstanceOf(Node::class, $boxer->getRoot());
    }

    public function testGetModelMap(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'x'; }
        };
        $boxer = Boxer::leaf('test', $item);
        $map = $boxer->getModelMap();

        $this->assertIsArray($map);
        $this->assertArrayHasKey('test', $map);
    }

    public function testGetItem(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'x'; }
        };
        $boxer = Boxer::leaf('my-address', $item);

        $retrieved = $boxer->getItem('my-address');

        $this->assertSame($item, $retrieved);
    }

    public function testGetItemNonExistent(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $this->assertNull($boxer->getItem('non-existent'));
    }

    public function testGetNode(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $node = $boxer->getNode('0');

        $this->assertInstanceOf(Node::class, $node);
    }

    public function testGetNodeNonExistent(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $this->assertNull($boxer->getNode('non-existent'));
    }

    public function testEditLeafUpdatesModelMap(): void
    {
        $originalItem = new class implements Item {
            public function render(): string { return 'original'; }
        };
        $newItem = new class implements Item {
            public function render(): string { return 'modified'; }
        };

        $boxer = Boxer::leaf('0', $originalItem);
        $edited = $boxer->editLeaf('0', fn($item) => $newItem);

        $this->assertSame('original', $boxer->getItem('0')->render());
        $this->assertSame('modified', $edited->getItem('0')->render());
    }

    public function testEditLeafNotFoundThrows(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $this->expectException(NotFoundError::class);
        $this->expectExceptionMessageMatches("/Address 'nonexistent' not found/");

        $boxer->editLeaf('nonexistent', fn($item) => $item);
    }

    public function testEditLeafWithArrayResult(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'original'; }
        };

        $boxer = Boxer::leaf('0', $item);
        $edited = $boxer->editLeaf('0', fn($item) => [$item, null]);

        $this->assertSame('original', $edited->getItem('0')->render());
    }

    public function testEditLeafWithError(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'x'; }
        };

        $boxer = Boxer::leaf('0', $item);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $boxer->editLeaf('0', fn($item) => [null, new \RuntimeException('test error')]);
    }

    public function testSetSizeReturnsSizer(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $sized = $boxer->setSize(80, 24);

        $this->assertInstanceOf(Sizer::class, $sized);
    }

    public function testSetSizeSameDimensionsReturnsSame(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        })->setSize(80, 24);

        $result = $boxer->setSize(80, 24);

        $this->assertSame($boxer, $result);
    }

    public function testSetSizeZeroOrNegativeReturnsEmpty(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        })->setSize(80, 24);

        $empty = $boxer->setSize(0, 10);

        $this->assertSame(0, $empty->getWidth());
    }

    public function testRenderWithoutSizeReturnsWaitingMessage(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        });

        $rendered = $boxer->render();

        $this->assertSame('waiting for size information', $rendered);
    }

    public function testRenderWithSizeAndProperModel(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return str_repeat('x', 20); }
        })->setSize(20, 1);

        $rendered = $boxer->render();

        $this->assertStringContainsString('x', $rendered);
    }

    public function testRenderReturnsSizeErrorMessage(): void
    {
        // A model that renders too many lines
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return "line1\nline2\nline3\nline4\nline5"; }
        })->setSize(20, 1);

        $rendered = $boxer->render();

        $this->assertStringContainsString('size error', $rendered);
    }

    public function testPersistAndRestoreState(): void
    {
        // Persistence is final — the real save/load round-trip IS the pin.
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/boxer-state.json';
            $persistence = new \SugarCraft\Dashboard\State\Persistence();

            $boxer = Boxer::leaf('0', new class implements Item {
                public function render(): string { return 'x'; }
            });

            $boxer->persistState($persistence, $path, ['0', '1']);
            $this->assertFileExists($path);

            $restored = $boxer->restoreState($persistence, $path);
            $this->assertSame(['0', '1'], $restored);
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testRestoreStateWithNoData(): void
    {
        $dir = self::stateSandbox();
        try {
            $persistence = new \SugarCraft\Dashboard\State\Persistence();
            $boxer = Boxer::leaf('0', new class implements Item {
                public function render(): string { return 'x'; }
            });

            $restored = $boxer->restoreState($persistence, $dir . '/nonexistent.json');
            $this->assertSame([], $restored);
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testRestoreStateWithMissingKey(): void
    {
        $dir = self::stateSandbox();
        try {
            $path = $dir . '/boxer-state.json';
            $persistence = new \SugarCraft\Dashboard\State\Persistence();
            $persistence->save($path, ['other' => 'data']);

            $boxer = Boxer::leaf('0', new class implements Item {
                public function render(): string { return 'x'; }
            });

            $restored = $boxer->restoreState($persistence, $path);
            $this->assertSame([], $restored);
        } finally {
            self::removeStateSandbox($dir);
        }
    }

    public function testGetWidth(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        })->setSize(100, 50);

        $this->assertSame(100, $boxer->getWidth());
    }

    public function testGetHeight(): void
    {
        $boxer = Boxer::leaf('0', new class implements Item {
            public function render(): string { return 'x'; }
        })->setSize(100, 50);

        $this->assertSame(50, $boxer->getHeight());
    }
    /**
     * D-39 (finding sugar-dashboard-033): the README-documented primary
     * path — build with a factory, edit by declared address — must work
     * without hand-feeding a modelMap first.
     */
    public function testFactoryBuiltTreeSupportsEditLeafOnDeclaredAddresses(): void
    {
        $boxer = Boxer::vertical(Node::leaf('/0'), Node::leaf('/1'));

        $item = new class implements Item {
            public function render(): string { return 'EDITED-PANEL'; }
        };

        $edited = $boxer->editLeaf('/0', static fn (Item $model): Item => $item);
        $sized = $edited->setSize(20, 3);
        $rendered = $sized->render();

        $this->assertStringContainsString('EDITED-PANEL', $rendered);
        $this->assertStringNotContainsString('size error', $rendered);
        $this->assertStringNotContainsString('not found', $rendered);
    }

    public function testUntouchedFactorySlotRendersBlankNotError(): void
    {
        $boxer = Boxer::horizontal(Node::leaf('/0'), Node::leaf('/1'))->setSize(20, 2);

        // Both slots are still placeholders: blank content, but the
        // layout renders — no SizeError 'model not found' leak.
        $this->assertStringNotContainsString('size error', $boxer->render());
        $map = $boxer->modelMap();
        $this->assertArrayHasKey('/0', $map);
        $this->assertArrayHasKey('/1', $map);
        $this->assertInstanceOf(PlaceholderItem::class, $map['/0']);
        $this->assertSame('', $map['/0']->render());
    }

    public function testEditLeafStillThrowsForUndeclaredAddress(): void
    {
        $boxer = Boxer::vertical(Node::leaf('/0'), Node::leaf('/1'));

        $this->expectException(NotFoundError::class);
        $boxer->editLeaf('/9', static fn (Item $model): Item => $model);
    }

    public function testTreeFactoryKeepsSuppliedModelsOverPlaceholders(): void
    {
        $item = new class implements Item {
            public function render(): string { return 'GIVEN'; }
        };
        $boxer = Boxer::tree(Node::vertical(Node::leaf('0'), Node::leaf('1')), ['0' => $item]);

        $map = $boxer->modelMap();
        $this->assertSame($item, $map['0']);
        $this->assertInstanceOf(PlaceholderItem::class, $map['1']);
    }

    private static function stateSandbox(): string
    {
        $dir = sys_get_temp_dir() . '/dash-state-' . bin2hex(random_bytes(4));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('could not create state sandbox');
        }

        return $dir;
    }

    private static function removeStateSandbox(string $dir): void
    {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
    // ═══════════════════════════════════════════════════════════════
    // Moved from tests/Layout/BoxerTest.php (D-159 suite consolidation:
    // one suite per class, namespace follows src/Layout/Boxer/).
    // Dropped-duplicate disclosure: the old
    // testRenderWithoutSizeReturnsWaitingMessage asserted the same
    // message through a different fixture helper than the leg above.
    // ═══════════════════════════════════════════════════════════════

    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }


    private function sizedItem(int $w = 0, int $h = 0): Item
    {
        return new class($w, $h) implements Item, Sizer {
            public int $capturedW = 0;
            public int $capturedH = 0;

            public function __construct(
                private int $w,
                private int $h,
            ) {}

            public function setSize(int $width, int $height): Sizer
            {
                $clone = clone $this;
                $clone->capturedW = $width;
                $clone->capturedH = $height;
                $clone->w = $width;
                $clone->h = $height;
                return $clone;
            }

            public function render(): string
            {
                return "Size:{$this->w}x{$this->h}";
            }
        };
    }

    public function testBoxerImplementsItem(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $this->assertInstanceOf(Item::class, $boxer);
    }

    public function testBoxerImplementsSizer(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $boxer);
    }

    public function testCreateLeafReturnsNodeWithAddress(): void
    {
        $boxer = Boxer::leaf('0.1.2', $this->strItem('content'));
        $node = $boxer->getNode('0.1.2');

        $this->assertNotNull($node);
        $this->assertSame('0.1.2', $node->getAddress());
        $this->assertTrue($node->isLeaf());
    }

    public function testCreateLeafWithEmptyAddressThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Boxer::leaf('', $this->strItem('test'));
    }

    public function testHorizontalFactoryCreatesHorizontalNode(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        $boxer = Boxer::horizontal($child1, $child2);

        $this->assertFalse($boxer->getRoot()->isLeaf());
        $this->assertFalse($boxer->getRoot()->isVerticalStacked());
        $this->assertCount(2, $boxer->getRoot()->getChildren());
    }

    public function testVerticalFactoryCreatesVerticalNode(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        $boxer = Boxer::vertical($child1, $child2);

        $this->assertFalse($boxer->getRoot()->isLeaf());
        $this->assertTrue($boxer->getRoot()->isVerticalStacked());
        $this->assertCount(2, $boxer->getRoot()->getChildren());
    }

    public function testEditLeafModifiesNode(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('original'));
        $modifiedBoxer = $boxer->editLeaf('0', fn($item) => $this->strItem('modified'));

        // Original unchanged
        $this->assertSame('original', $boxer->getItem('0')->render());

        // New instance has modified content
        $this->assertSame('modified', $modifiedBoxer->getItem('0')->render());
    }

    public function testEditLeafAutoSaves(): void
    {
        $sized = $this->sizedItem();
        $boxer = Boxer::leaf('0', $sized);

        // Set size to propagate
        $boxer = $boxer->setSize(20, 5);

        // Edit leaf
        $modifiedBoxer = $boxer->editLeaf('0', function ($item) {
            return $item->setSize(15, 3);
        });

        // Verify the modification persisted
        $this->assertSame(15, $modifiedBoxer->getItem('0')->capturedW ?? 0);
        $this->assertSame(3, $modifiedBoxer->getItem('0')->capturedH ?? 0);
    }

    public function testEditLeafThrowsNotFoundForUnknownAddress(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));

        $this->expectException(NotFoundError::class);
        $boxer->editLeaf('99', fn($item) => $item);
    }

    public function testEditLeafRethrowsErrorFromEditFunc(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));

        $this->expectException(\RuntimeException::class);
        $boxer->editLeaf('0', function ($item) {
            throw new \RuntimeException('edit failed');
        });
    }

    public function testEvenSplitHorizontal(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::horizontal($child1, $child2, $child3);
        $boxer = $boxer->setSize(30, 10);

        // Each child should get 10 width (30 / 3)
        $this->assertSame(10, $boxer->getNode('0')->getWidth());
        $this->assertSame(10, $boxer->getNode('1')->getWidth());
        $this->assertSame(10, $boxer->getNode('2')->getWidth());

        // All should have full height
        $this->assertSame(10, $boxer->getNode('0')->getHeight());
        $this->assertSame(10, $boxer->getNode('1')->getHeight());
        $this->assertSame(10, $boxer->getNode('2')->getHeight());
    }

    public function testEvenSplitVertical(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::vertical($child1, $child2, $child3);
        $boxer = $boxer->setSize(20, 30);

        // Each child should get 10 height (30 / 3)
        $this->assertSame(10, $boxer->getNode('0')->getHeight());
        $this->assertSame(10, $boxer->getNode('1')->getHeight());
        $this->assertSame(10, $boxer->getNode('2')->getHeight());

        // All should have full width
        $this->assertSame(20, $boxer->getNode('0')->getWidth());
        $this->assertSame(20, $boxer->getNode('1')->getWidth());
        $this->assertSame(20, $boxer->getNode('2')->getWidth());
    }

    public function testEvenSplitWithRemainder(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::horizontal($child1, $child2, $child3);
        $boxer = $boxer->setSize(31, 10);  // 31 / 3 = 10 remainder 1

        // First child gets the extra
        $this->assertSame(11, $boxer->getNode('0')->getWidth());
        $this->assertSame(10, $boxer->getNode('1')->getWidth());
        $this->assertSame(10, $boxer->getNode('2')->getWidth());
    }

    public function testSizeFuncOverridesEvenSplit(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        // Custom sizeFunc that gives 70% to first, 30% to second
        $sizeFunc = fn(Node $node, int $totalWidth): array => [
            (int) ($totalWidth * 0.7),
            (int) ($totalWidth * 0.3),
        ];

        $root = Node::horizontal($child1, $child2)->withCustomSizeFunc($sizeFunc);
        $boxer = Boxer::tree($root)->setSize(100, 20);

        // Should use the sizeFunc distribution
        $this->assertEquals(70, $boxer->getNode('0')->getWidth());
        $this->assertEquals(30, $boxer->getNode('1')->getWidth());
    }

    public function testAddressUniqueness(): void
    {
        $boxer1 = Boxer::leaf('leaf-a', $this->strItem('content1'));
        $boxer2 = Boxer::leaf('leaf-b', $this->strItem('content2'));

        $this->assertSame('leaf-a', $boxer1->getNode('leaf-a')->getAddress());
        $this->assertSame('leaf-b', $boxer2->getNode('leaf-b')->getAddress());
        $this->assertNotSame($boxer1->getNode('leaf-a')->getAddress(), $boxer2->getNode('leaf-b')->getAddress());
    }

    public function testNestedAddresses(): void
    {
        $leaf1 = Node::leaf('0');
        $leaf2 = Node::leaf('1');
        $leaf3 = Node::leaf('0.0');  // Child of leaf1's would-be parent
        $leaf4 = Node::leaf('0.1');

        $boxer = Boxer::vertical(
            Node::horizontal($leaf1, $leaf2),
            Node::horizontal($leaf3, $leaf4),
        );

        $this->assertSame('0', $boxer->getNode('0')->getAddress());
        $this->assertSame('1', $boxer->getNode('1')->getAddress());
        $this->assertSame('0.0', $boxer->getNode('0.0')->getAddress());
        $this->assertSame('0.1', $boxer->getNode('0.1')->getAddress());
    }

    public function testBoxerRendersWithContent(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('Hello World'));
        $boxer = $boxer->setSize(20, 3);

        $rendered = $boxer->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testBoxerSetSizeReturnsNewInstance(): void
    {
        $original = Boxer::leaf('0', $this->strItem('test'));
        $resized = $original->setSize(10, 5);

        $this->assertNotSame($original, $resized);
        $this->assertSame(0, $original->getWidth());
        $this->assertSame(0, $original->getHeight());
        $this->assertSame(10, $resized->getWidth());
        $this->assertSame(5, $resized->getHeight());
    }

    public function testNestedBoxerLayout(): void
    {
        // Create a nested layout: [ [A][B] ] [C] ]
        // This is: vertical containing horizontal(A,B) and C

        $itemA = $this->strItem('A');
        $itemB = $this->strItem('B');
        $itemC = $this->strItem('C');

        $root = Node::vertical(
            Node::horizontal(Node::leaf('0'), Node::leaf('1')),
            Node::leaf('2')
        );

        $boxer = Boxer::tree($root, [
            '0' => $itemA,
            '1' => $itemB,
            '2' => $itemC,
        ])->setSize(30, 10);

        $rendered = $boxer->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);
    }

    public function testSetSizeToZeroReturnsZeroSizedBoxer(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $boxer = $boxer->setSize(10, 5);
        $boxer = $boxer->setSize(0, 0);

        $this->assertSame(0, $boxer->getWidth());
        $this->assertSame(0, $boxer->getHeight());
        $this->assertSame('waiting for size information', $boxer->render());
    }

    public function testGetModelMapCarriesStringItemFixtures(): void
    {
        // Moved-leg pin (D-159): the strItem fixture shape
        $item = $this->strItem('content');
        $boxer = Boxer::leaf('my-address', $item);

        $map = $boxer->getModelMap();

        $this->assertArrayHasKey('my-address', $map);
        $this->assertSame($item, $map['my-address']);
    }

    public function testGetItemResolvesStringItemAddressAndMissIsNull(): void
    {
        // Moved-leg pin (D-159): the strItem fixture shape, plus the miss leg
        $item = $this->strItem('content');
        $boxer = Boxer::leaf('addr', $item);

        $this->assertSame($item, $boxer->getItem('addr'));
        $this->assertNull($boxer->getItem('non-existent'));
    }

    public function testGetNodeResolvesStringAddressNotation(): void
    {
        // Moved-leg pin (D-159): string-address notation lookup
        $boxer = Boxer::leaf('test-addr', $this->strItem('test'));

        $node = $boxer->getNode('test-addr');

        $this->assertNotNull($node);
        $this->assertSame('test-addr', $node->getAddress());
    }

    public function testGetNodeReturnsNullForNonexistent(): void
    {
        $boxer = Boxer::leaf('exists', $this->strItem('test'));

        $this->assertNull($boxer->getNode('does-not-exist'));
    }

    public function testTreeWithEmptyModelMapSeedsPlaceholders(): void
    {
        $boxer = Boxer::tree(Node::horizontal(Node::leaf('0'), Node::leaf('1')));

        $map = $boxer->getModelMap();
        // PHP coerces numeric-string addresses to int array keys; the
        // lib casts them back on every read (setSize does (string) $addr).
        $this->assertSame(['0', '1'], array_map('strval', array_keys($map)));
        foreach ($map as $address => $model) {
            $this->assertInstanceOf(Item::class, $model, "seeded slot {$address}");
            $this->assertSame('', $model->render());
        }
    }

    public function testSetSizeIdempotent(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $boxer = $boxer->setSize(10, 5);
        $sameBoxer = $boxer->setSize(10, 5);

        $this->assertSame($boxer, $sameBoxer);
    }

    public function testEditLeafReturnsNewInstance(): void
    {
        $original = Boxer::leaf('0', $this->strItem('original'));
        $modified = $original->editLeaf('0', fn($item) => $this->strItem('modified'));

        $this->assertNotSame($original, $modified);
        $this->assertSame('original', $original->getItem('0')->render());
        $this->assertSame('modified', $modified->getItem('0')->render());
    }

    public function testSizeErrorMessageCannotLeakControlBytes(): void
    {
        // Finding M-6: SizeError messages embed the offending model line verbatim -
        // hostile content must not reach the terminal raw through the frame.
        $boxer = Boxer::leaf('0', $this->strItem("\x1b[31m" . str_repeat('E', 40) . "\x1b[0m"));

        $rendered = $boxer->setSize(5, 2)->render();

        $this->assertStringContainsString('size error', $rendered);
        $this->assertSame(0, substr_count($rendered, "\x1b"));
    }
}
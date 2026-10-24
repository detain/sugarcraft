<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\Boxer\Address;
use SugarCraft\Dashboard\Layout\Boxer\Boxer;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Layout\Boxer\NotFoundError;
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

    public function testLeafFactoryEmptyAddressThrows(): void
    {
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
}

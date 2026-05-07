<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\{DefaultPrefixer, DefaultSuffixer, Model, StringItem};
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    private Model $model;

    protected function setUp(): void
    {
        $this->model = Model::new()
            ->setViewport(80, 24)
            ->setCursorOffset(3);
    }

    public function testNewModelIsNotEmpty(): void
    {
        $m = Model::new();
        $this->assertSame(80, $m->width);
        $this->assertSame(24, $m->height);
        $this->assertSame(5, $m->cursorOffset);
    }

    public function testAddItem(): void
    {
        $m = $this->model->addItem(new StringItem('hello'));
        $this->assertSame(1, $m->length());
        $this->assertFalse($m->isEmpty());
    }

    public function testFluentSetters(): void
    {
        $m = $this->model
            ->setWidth(100)
            ->setHeight(30)
            ->setViewport(120, 40)
            ->setCursorOffset(7)
            ->setWrap(5);

        $this->assertSame(120, $m->width);
        $this->assertSame(40, $m->height);
        $this->assertSame(7, $m->cursorOffset);
        $this->assertSame(5, $m->wrap);
    }

    public function testCursorNavigation(): void
    {
        $m = $this->model;
        foreach (['a', 'b', 'c'] as $v) {
            $m->addItem(new StringItem($v));
        }

        $this->assertSame(0, $m->cursorIndex());
        $m->cursorDown();
        $this->assertSame(1, $m->cursorIndex());
        $m->cursorDown();
        $this->assertSame(2, $m->cursorIndex());
        $m->cursorUp();
        $this->assertSame(1, $m->cursorIndex());
    }

    public function testCursorClampAtBounds(): void
    {
        $m = $this->model->addItem(new StringItem('only'));
        $m->cursorUp();  // should not go below 0
        $this->assertSame(0, $m->cursorIndex());
        $m->cursorDown(100);  // should not exceed length-1
        $this->assertSame(0, $m->cursorIndex());
    }

    public function testRemoveItem(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(3, $this->model->length());

        $this->model->removeItem(1);
        $this->assertSame(2, $this->model->length());
    }

    public function testClear(): void
    {
        foreach (['a', 'b'] as $v) {
            $this->model->addItem(new StringItem($v));
        }
        $this->assertSame(2, $this->model->length());
        $this->model->clear();
        $this->assertTrue($this->model->isEmpty());
    }

    public function testSortWithLessFunc(): void
    {
        foreach (['z', 'a', 'm'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->lessFunc = fn($a, $b) => \strcmp((string) $a, (string) $b);
        $this->model->sort();

        $this->assertSame('a', (string) $this->model->cursorItem());
        $this->assertSame('a', $this->model->lines()[0]);
    }

    public function testFindItem(): void
    {
        foreach (['apple', 'banana', 'cherry'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->assertSame(1, $this->model->find(new StringItem('banana')));
        $this->assertSame(-1, $this->model->find(new StringItem('durian')));
    }

    public function testFindWithEqualsFunc(): void
    {
        foreach (['aa', 'bb', 'cc'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->equalsFunc = fn($a, $b) => (string) $a === (string) $b;
        $this->assertSame(0, $this->model->find(new StringItem('aa')));
        $this->assertSame(-1, $this->model->find(new StringItem('xx')));
    }

    public function testSetCursor(): void
    {
        foreach (['a', 'b', 'c'] as $v) {
            $this->model->addItem(new StringItem($v));
        }

        $this->model->setCursor(2);
        $this->assertSame(2, $this->model->cursorIndex());
    }

    public function testLinesThrowsOnEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testLinesThrowsOnZeroViewport(): void
    {
        $this->model->addItem(new StringItem('x'))->setViewport(0, 10);
        $this->expectException(\RuntimeException::class);
        $this->model->lines();
    }

    public function testViewReturnsString(): void
    {
        $this->model
            ->addItem(new StringItem('item one'))
            ->addItem(new StringItem('item two'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $view = $this->model->View();
        $this->assertIsString($view);
        $this->assertStringContainsString('item one', $view);
        $this->assertStringContainsString('item two', $view);
    }

    public function testPrefixerInterface(): void
    {
        $this->model
            ->addItem(new StringItem('test item'))
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer())
            ->setViewport(80, 24);

        $lines = $this->model->lines();
        $this->assertNotEmpty($lines);
    }

    public function testStringItem(): void
    {
        $item = new StringItem('my string');
        $this->assertSame('my string', (string) $item);
        $this->assertSame('my string', $item->value);
    }

    public function testWrapLimitsLines(): void
    {
        $longText = \str_repeat('word ', 50);  // long enough to wrap
        $this->model
            ->addItem(new StringItem($longText))
            ->setViewport(20, 24)
            ->setWrap(3)
            ->setPrefixer(new DefaultPrefixer())
            ->setSuffixer(new DefaultSuffixer());

        $lines = $this->model->lines();
        $this->assertLessThanOrEqual(3, \count($lines));
    }

    public function testAddItemReturnsSelf(): void
    {
        $m = $this->model->addItem(new StringItem('x'));
        $this->assertSame($this->model, $m);
    }
}

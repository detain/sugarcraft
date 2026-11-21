<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;
use SugarCraft\Core\SubscriptionCapable;

/** Immutable counter used as a base layer: '+' bumps n, everything else is ignored. */
final class BaseRestoreCounterModel implements Model
{
    use SubscriptionCapable;

    public function __construct(public readonly int $n = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->rune === '+') {
            return [new self($this->n + 1), null];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "base n={$this->n}";
    }
}

/**
 * A popped screen must hand the base layer back: the root's own
 * currentModel was overwritten by the push, so the base has to be parked
 * on the way in and restored on the way out.
 */
final class RootModelBaseLayerRestoreTest extends TestCase
{
    private function plus(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, '+');
    }

    private function pushed(RootModelWithScreenStack $root): RootModelWithScreenStack
    {
        $screen = new Screen(new BaseRestoreCounterModel(100), title: 'Top');
        return $root->update(new ScreenStackPushedMsg($screen))[0];
    }

    public function testPopRestoresTheBaseLayerAsTheActiveModel(): void
    {
        $base = new BaseRestoreCounterModel(2);
        $root = $this->pushed(new RootModelWithScreenStack(currentModel: $base));

        [$next] = $root->update(new ScreenStackPoppedMsg());

        $this->assertSame($base, $next->currentModel, 'the base layer must come back on the pop');
        $this->assertSame('base n=2', $next->view(), 'the root must render the base, not an empty frame');
    }

    public function testKeysReachTheRestoredBaseLayerAfterThePop(): void
    {
        $root = $this->pushed(new RootModelWithScreenStack(currentModel: new BaseRestoreCounterModel(2)));
        [$popped] = $root->update(new ScreenStackPoppedMsg());

        [$next, $cmd] = $popped->update($this->plus());

        $this->assertNull($cmd);
        $this->assertInstanceOf(BaseRestoreCounterModel::class, $next->currentModel);
        $this->assertSame(3, $next->currentModel->n, 'the base must receive Msgs once the stack is empty again');
    }

    public function testTheBaseLayerKeepsItsPrePushStateAcrossAPushAndPop(): void
    {
        $root = new RootModelWithScreenStack(currentModel: new BaseRestoreCounterModel(7));
        $pushed = $this->pushed($root);
        [$popped] = $pushed->update(new ScreenStackPoppedMsg());

        $this->assertSame(7, $popped->currentModel->n);
    }

    public function testAPushedRootPopsBackToAnEmptyRootWhenThereWasNoBase(): void
    {
        $root = $this->pushed(new RootModelWithScreenStack());
        [$popped] = $root->update(new ScreenStackPoppedMsg());

        $this->assertNull($popped->currentModel);
        $this->assertSame('', $popped->view());
    }

    public function testWithScreensEmptyingTheStackRestoresTheBaseLayer(): void
    {
        $base = new BaseRestoreCounterModel(4);
        $root = $this->pushed(new RootModelWithScreenStack(currentModel: $base));

        $next = $root->withScreens(new ScreenStack());

        $this->assertSame($base, $next->currentModel);
        $this->assertSame('base n=4', $next->view());
    }

    public function testWithScreensEmptyOverAnEmptyStackKeepsTheBase(): void
    {
        $base = new BaseRestoreCounterModel(6);
        $next = (new RootModelWithScreenStack(currentModel: $base))->withScreens(new ScreenStack());

        $this->assertSame($base, $next->currentModel, 'an empty write over an empty stack must not drop the base');
        $this->assertSame('base n=6', $next->view());
    }

    public function testWithScreensPushingOverABaseParksIt(): void
    {
        $base = new BaseRestoreCounterModel(5);
        $root = new RootModelWithScreenStack(currentModel: $base);

        $pushed = $root->withScreens(
            (new ScreenStack())->push(new Screen(new BaseRestoreCounterModel(0), title: 'Top')),
        );
        $popped = $pushed->withScreens(new ScreenStack());

        $this->assertSame($base, $popped->currentModel);
    }
}

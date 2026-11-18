<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\SubscriptionCapable;

/** Immutable counter: '+' bumps n and asks for a quit Cmd, anything else is ignored. */
final class DelegationCounterModel implements Model
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
            return [new self($this->n + 1), Cmd::quit()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "n={$this->n}";
    }
}

final class RootModelScreenDelegationTest extends TestCase
{
    private function plus(): KeyMsg
    {
        return new KeyMsg(KeyType::Char, '+');
    }

    public function testANonStackMsgReachesTheTopScreenAndTheUpdateIsWrittenBack(): void
    {
        $root = (new RootModelWithScreenStack())->update(
            new ScreenStackPushedMsg(new Screen(new DelegationCounterModel(), title: 'Counter')),
        )[0];

        [$next, $cmd] = $root->update($this->plus());

        $this->assertInstanceOf(RootModelWithScreenStack::class, $next);
        $this->assertNotSame($root, $next, 'the root is immutable: a new instance carries the write-back');
        $this->assertSame(1, $next->screens()->current()->model->n, 'the top screen received the Msg');
        $this->assertSame(0, $root->screens()->current()->model->n, 'the previous root is untouched');
        $this->assertSame('n=1', $next->view());
    }

    public function testTheTopScreensCmdIsReturnedToTheCaller(): void
    {
        $root = (new RootModelWithScreenStack())->update(
            new ScreenStackPushedMsg(new Screen(new DelegationCounterModel(), title: 'Counter')),
        )[0];

        [, $cmd] = $root->update($this->plus());

        $this->assertNotNull($cmd, 'the screen returned a Cmd, so the root must forward it');
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testWithNoScreenPushedTheBaseModelIsUpdatedAndWrittenBack(): void
    {
        $root = new RootModelWithScreenStack(currentModel: new DelegationCounterModel());

        [$next, $cmd] = $root->update($this->plus());

        $this->assertNotSame($root, $next);
        $this->assertInstanceOf(DelegationCounterModel::class, $next->currentModel);
        $this->assertSame(1, $next->currentModel->n);
        $this->assertSame(0, $root->currentModel->n);
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testWithNothingToDelegateToTheRootReturnsItselfAndNoCmd(): void
    {
        $root = new RootModelWithScreenStack();

        [$next, $cmd] = $root->update($this->plus());

        $this->assertSame($root, $next);
        $this->assertNull($cmd);
    }

    public function testStackMessagesAreStillHandledByTheRootNotTheTopScreen(): void
    {
        $root = new RootModelWithScreenStack();

        [$pushed] = $root->update(new ScreenStackPushedMsg(new Screen(new DelegationCounterModel(), title: 'A')));

        $this->assertSame(['A'], $pushed->screens()->breadcrumb());
        $this->assertSame(0, $pushed->screens()->current()->model->n, 'a push is not delivered to the screen as input');
    }
}

<?php

declare(strict_types=1);

/**
 * ScreenStack demo — a 3-deep drill-down with push/pop navigation.
 *
 *   php examples/screen-stack.php
 */

require __DIR__ . '/../vendor/autoload.php';

require __DIR__ . '/guard.php';

interactiveDemoGuard('screen-stack');

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ScreenStackPushedMsg;
use SugarCraft\Core\Msg\ScreenStackPoppedMsg;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\RootModelWithScreenStack;
use SugarCraft\Core\Screen;
use SugarCraft\Core\ScreenStack;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Simple screen model that shows its level and handles quit.
 */
final class ScreenModel implements Model
{
    use SubscriptionCapable;

    public function __construct(
        public readonly int $level,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof QuitMsg) {
            return [$this, Cmd::quit()];
        }
        // sc-44 (finding sugar-core-068): Program routes keystrokes to the
        // ACTIVE SCREEN, not the stack owner, so the root model's own `q`
        // arm is unreachable while any screen is pushed — the demo shipped
        // a help line ("q — quit") that only worked on an empty stack and
        // the once-QA run reproduced the hang under a PTY. The pushed
        // screens answer q themselves now; the showcase quits from depth.
        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "Screen Level {$this->level}\n";
    }
}

/**
 * Root model that owns the ScreenStack and delegates to active screens.
 */
final class DemoRootModel implements Model, \SugarCraft\Core\ScreenStackCapable
{
    use SubscriptionCapable;

    /** @var list<string> */
    public array $pushedIds = [];

    public function __construct(
        public ScreenStack $screens = new ScreenStack(),
        public ?Model $currentModel = null,
    ) {
    }

    public function screens(): ScreenStack
    {
        return $this->screens;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof ScreenStackPushedMsg) {
            $next = clone $this;
            $next->pushedIds[] = (string) $msg->screen->model->level;
            $msg->screen->onEnter?->__invoke();
            $next->screens = $next->screens->push($msg->screen);
            $next->currentModel = $msg->screen->model;
            return [$next, null];
        }

        if ($msg instanceof ScreenStackPoppedMsg) {
            $next = clone $this;
            if (!$next->screens->isEmpty()) {
                $popped = $next->screens->current();
                $popped->onExit?->__invoke();
                $next->pushedIds[] = 'pop:' . $popped->model->level;
                $next->screens = $next->screens->pop();
                $next->currentModel = $next->screens->isEmpty()
                    ? null
                    : $next->screens->current()->model;
            }
            return [$next, null];
        }

        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        $active = $this->currentModel ?? new ScreenModel(0);
        $crumb = implode(' > ', $this->screens->breadcrumb());
        $pushed = implode(', ', $this->pushedIds);
        return <<<VIEW
Screen Stack Demo — 3-deep drill-down

Current: Level {$active->view()}
Breadcrumb: [{$crumb}]
Pushed: {$pushed}

Controls:
  p  — push next level screen
  q  — quit

VIEW;
    }
}

$root = new DemoRootModel();
$program = new Program($root);

// Push initial screens
$program->send(new ScreenStackPushedMsg(
    new Screen(new ScreenModel(1), title: 'Level 1')
));
$program->send(new ScreenStackPushedMsg(
    new Screen(new ScreenModel(2), title: 'Level 2')
));
$program->send(new ScreenStackPushedMsg(
    new Screen(new ScreenModel(3), title: 'Level 3')
));

// Pop once
$program->send(new ScreenStackPoppedMsg());

$program->run();

echo "\nFinal pushed/popped: " . implode(', ', $root->pushedIds) . "\n";

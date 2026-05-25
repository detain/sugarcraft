<?php

declare(strict_types=1);

/**
 * COMPOSITION: candy-core Program/Model  +  candy-forms Spinner (sub-program)
 *
 * Demonstrates the "program-within-a-program" pattern: a candy-core root
 * `Model` owns two child models and routes the Elm-architecture
 * `update()`/`view()` contract down to whichever child is focused. One child
 * is a hand-written counter; the other is a candy-forms `Spinner` — a fully
 * independent model embedded as a sub-program.
 *
 * The composition rule candy-core encodes: a parent forwards each `Msg` to
 * its focused child's `update()`, threads the returned child model back into
 * itself, and stitches the children's `view()` strings into one frame. Any
 * Model nests inside any other Model this way.
 *
 * To stay runnable in CI / non-tty, this drives `update()` with a scripted
 * message sequence and renders the final frame instead of calling
 * `Program::run()` (which would block on an stdin event loop).
 *
 * Run:
 *   composer install && php program-within-program.php
 */

require __DIR__ . '/vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Spinner\Spinner;
use SugarCraft\Forms\Spinner\Style as SpinnerStyle;
use SugarCraft\Forms\Spinner\TickMsg;

/** A trivial child model (sub-program A). */
final class Counter implements Model
{
    public function __construct(public readonly int $count = 0)
    {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            return match ($msg->type) {
                KeyType::Up   => [new self($this->count + 1), null],
                KeyType::Down => [new self(max(0, $this->count - 1)), null],
                default       => [$this, null],
            };
        }
        return [$this, null];
    }

    public function view(): string
    {
        return "count = {$this->count}";
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

/**
 * Root model — owns a Counter and a candy-forms Spinner as sub-programs and
 * forwards each Msg to whichever one currently has focus (Tab switches).
 */
final class Dashboard implements Model
{
    public function __construct(
        public readonly Counter $counter = new Counter(),
        public readonly ?Spinner $spinner = null,
        public readonly int $focus = 0,   // 0 = counter, 1 = spinner
    ) {
        // Lazily create the spinner so the ctor stays a valid default factory.
    }

    public static function new(): self
    {
        return new self(new Counter(), Spinner::new(SpinnerStyle::dot()), 0);
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        // Tab toggles which sub-program receives subsequent messages.
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Tab) {
            return [new self($this->counter, $this->spinner, $this->focus === 0 ? 1 : 0), null];
        }

        // Forward to the focused child and thread its new state back in.
        if ($this->focus === 0) {
            [$child, $cmd] = $this->counter->update($msg);
            \assert($child instanceof Counter);
            return [new self($child, $this->spinner, $this->focus), $cmd];
        }

        [$child, $cmd] = $this->spinner->update($msg);
        \assert($child instanceof Spinner);
        return [new self($this->counter, $child, $this->focus), $cmd];
    }

    public function view(): string
    {
        $mark = fn (int $i): string => $i === $this->focus ? '▸ ' : '  ';
        $counterLine = $mark(0) . 'Counter:  ' . $this->counter->view();
        $spinnerLine = $mark(1) . 'Spinner:  ' . $this->spinner->view();

        return "Nested models — Tab switches focus, ↑/↓ drive the focused child\n\n"
            . $counterLine . "\n"
            . $spinnerLine . "\n";
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }
}

// Scripted message sequence (what a real event loop would feed from stdin):
//   ↑ ↑ ↑   -> counter focused, count becomes 3
//   Tab     -> focus moves to the spinner sub-program
//   tick×4  -> spinner advances 4 frames
$script = [
    new KeyMsg(KeyType::Up),
    new KeyMsg(KeyType::Up),
    new KeyMsg(KeyType::Up),
    new KeyMsg(KeyType::Tab),
];

$model = Dashboard::new();
foreach ($script as $msg) {
    [$model, ] = $model->update($msg);
}
// After Tab, the spinner is focused; route ticks to it by its own id.
\assert($model instanceof Dashboard);
$spinnerId = $model->spinner->id();
for ($i = 0; $i < 4; $i++) {
    [$model, ] = $model->update(new TickMsg($spinnerId));
}

echo $model->view() . "\n";

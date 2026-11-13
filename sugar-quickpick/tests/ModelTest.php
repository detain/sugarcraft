<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\QuickPick\{Model, QuickPick};

/**
 * The Model contract, pinned honestly (-015).
 *
 * The previous file drove anonymous doubles that only re-asserted their own
 * match() arms (`assertSame($model, $newModel)` on a double that returns
 * `$this` is a pure self-assert with zero library code), and re-exercised
 * cursorDown/type/backspace primitives already pinned in QuickPickTest.
 * Both mirrors are gone. What remains is exactly what a contract test may
 * claim: (1) the interface SHAPE — the two methods and their signatures,
 * which every host compiles against; and (2) ONE host-owned loop over a
 * REAL QuickPick, proving the documented protocol composes end to end.
 *
 * @covers \SugarCraft\QuickPick\QuickPick
 */
final class ModelTest extends TestCase
{
    public function testInterfaceShapeIsPinned(): void
    {
        $reflection = new \ReflectionClass(Model::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame(
            ['update', 'view'],
            \array_map(static fn(\ReflectionMethod $m): string => $m->getName(), $reflection->getMethods()),
            'the contract is exactly update() + view() — a third member is a silent break for implementing hosts',
        );

        $update = $reflection->getMethod('update');
        $params = $update->getParameters();
        self::assertCount(2, $params);
        self::assertSame(QuickPick::class, (string) $params[0]->getType());
        self::assertSame('string', (string) $params[1]->getType());
        self::assertSame(Model::class, (string) $update->getReturnType());

        $view = $reflection->getMethod('view');
        self::assertSame(QuickPick::class, (string) $view->getParameters()[0]->getType());
        self::assertSame('string', (string) $view->getReturnType());
    }

    public function testHostOwnedLoopDrivesRealQuickPickThroughTheProtocol(): void
    {
        $host = new class implements Model {
            public QuickPick $pick;

            public function update(QuickPick $model, string $msg): Model
            {
                $this->pick = match (true) {
                    $msg === 'down'      => $model->cursorDown(),
                    $msg === 'backspace' => $model->backspace(),
                    $msg === 'enter'     => $model, // host-vocabulary token — the library decodes nothing
                    \str_starts_with($msg, 'type:') => $model->type(\substr($msg, 5)),
                    default              => $model,
                };
                return $this;
            }

            public function view(QuickPick $model): string
            {
                return $model->view(\str_repeat('.', 40));
            }
        };

        $start = QuickPick::new(['apple', 'banana', 'cherry'])
            ->setWindowWidth(20)
            ->setWindowHeight(5)
            ->show();

        $returned = $host->update($start, 'type:a');
        self::assertSame($host, $returned, 'update() carries the host back');
        self::assertSame(2, $host->pick->itemCount(), "type:a keeps apple + banana (cherry has no 'a')");

        $host->update($host->pick, 'down');
        self::assertSame(1, $host->pick->cursor());
        self::assertSame('banana', $host->pick->selected()?->value());

        $host->update($host->pick, 'enter');
        self::assertSame('a', $host->pick->filterText(), 'an unmapped host token is identity, not a reset');

        $host->update($host->pick, 'backspace');
        self::assertSame('', $host->pick->filterText());
        self::assertSame(3, $host->pick->itemCount(), 'the full corpus returns');

        $frame = $host->view($host->pick);
        self::assertStringContainsString('apple', $frame, 'the loop renders through QuickPick::view()');
        self::assertStringContainsString('─', $frame, 'the separator belongs to the overlay, not the background dots');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Hermit\{FilteredItem, Hermit, Item, Model};
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Hermit\Model
 */
final class ModelTest extends TestCase
{
    public function testUpdateHandlesDownMessage(): void
    {
        $model = new class implements Model {
            public function update(Hermit $h, string $msg): Model
            {
                return match ($msg) {
                    'down' => $this,
                    default => $this,
                };
            }

            public function view(Hermit $h): string
            {
                return $h->View('');
            }
        };

        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
        ];
        $hermit = Hermit::new($items)->show();
        $newModel = $model->update($hermit, 'down');

        self::assertSame($model, $newModel);
    }

    public function testUpdateMapsDownToCursorDown(): void
    {
        $model = new class implements Model {
            private Hermit $hermit;

            public function update(Hermit $h, string $msg): Model
            {
                $this->hermit = match ($msg) {
                    'down' => $h->cursorDown(),
                    'backspace' => $h->backspace(),
                    default => $h,
                };

                return $this;
            }

            public function view(Hermit $h): string
            {
                return $h->View('');
            }

            public function hermit(): Hermit
            {
                return $this->hermit;
            }
        };

        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];
        $hermit = Hermit::new($items)->show();
        $result = $model->update($hermit, 'down');

        self::assertSame(1, $result->hermit()->cursor());
    }

    public function testUpdateMapsTypeMessage(): void
    {
        $model = new class implements Model {
            private Hermit $hermit;

            public function update(Hermit $h, string $msg): Model
            {
                if (str_starts_with($msg, 'type:')) {
                    $this->hermit = $h->type(substr($msg, 5));
                } else {
                    $this->hermit = $h;
                }

                return $this;
            }

            public function view(Hermit $h): string
            {
                return $h->View('');
            }

            public function hermit(): Hermit
            {
                return $this->hermit;
            }
        };

        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];
        $hermit = Hermit::new($items)->show();
        $result = $model->update($hermit, 'type:ban');

        self::assertSame(1, $result->hermit()->itemCount());
        self::assertSame('banana', $result->hermit()->selected()->value());
    }

    public function testViewReturnsRenderedString(): void
    {
        $items = [
            new FilteredItem(1, 'apple'),
        ];
        $hermit = Hermit::new($items)
            ->setWindowWidth(40)
            ->setWindowHeight(5)
            ->setOffset(0, 0)
            ->show();

        // Render over a proper background (matching HermitRankerTest pattern)
        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $view = $hermit->View($bg);

        self::assertIsString($view);
        // A shown Hermit should render the overlay onto the background
        self::assertStringContainsString('apple', $view);
    }

    public function testRoundTripSequence(): void
    {
        $model = new class implements Model {
            private Hermit $hermit;

            public function update(Hermit $h, string $msg): Model
            {
                $this->hermit = match (true) {
                    $msg === 'down' => $h->cursorDown(),
                    $msg === 'backspace' => $h->backspace(),
                    str_starts_with($msg, 'type:') => $h->type(substr($msg, 5)),
                    default => $h,
                };

                return $this;
            }

            public function view(Hermit $h): string
            {
                return $h->View('');
            }

            public function hermit(): Hermit
            {
                return $this->hermit;
            }
        };

        $items = [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];

        // Initial state: type 'a' → apple, banana (cherry has no 'a')
        $hermit = Hermit::new($items)->show();
        $model = $model->update($hermit, 'type:a');
        self::assertSame(2, $model->hermit()->itemCount()); // apple, banana

        // Move down
        $model = $model->update($model->hermit(), 'down');
        self::assertSame(1, $model->hermit()->cursor());

        // Backspace to clear filter → all 3 items restored
        $model = $model->update($model->hermit(), 'backspace');
        self::assertSame('', $model->hermit()->filterText());
        self::assertSame(3, $model->hermit()->itemCount());

        // Full round-trip: view produces a string
        $view = $model->view($model->hermit());
        self::assertIsString($view);
    }
}

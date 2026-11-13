<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\QuickPick\{Model, QuickPick, QuickPickHost};

/**
 * The composition contract, pinned honestly (-015 collapsed the doubles;
 * -006/-044 reshaped what they pinned).
 *
 * Three claims survive a contract test only if they check something the
 * library itself cannot dodge: (1) the interface SHAPE every host compiles
 * against — QuickPickHost with exactly update() + view() and the TUPLE
 * return, the old single-Model return forced hosts to smuggle the updated
 * picker through mutable state; (2) the deprecated `Model` alias resolves
 * without members of its own; (3) ONE host-owned loop over a REAL
 * QuickPick in which the host is a STATELESS object — every keystroke
 * threads through the return pair, proving the contract carries state
 * without the mutation -006 demanded.
 *
 * @covers \SugarCraft\QuickPick\QuickPick
 */
final class ModelTest extends TestCase
{
    public function testHostContractShapeIsPinned(): void
    {
        $reflection = new \ReflectionClass(QuickPickHost::class);

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
        self::assertSame('array', (string) $update->getReturnType(), '-006: the updated picker rides the return tuple');

        $view = $reflection->getMethod('view');
        self::assertSame(QuickPick::class, (string) $view->getParameters()[0]->getType());
        self::assertSame('string', (string) $view->getReturnType());
    }

    public function testDeprecatedModelAliasResolvesWithoutMembers(): void
    {
        // -044 soft landing: the old name still resolves for type-hints and
        // instanceof — but it declares nothing new, so QuickPickHost is the
        // single source of the shape.
        $alias = new \ReflectionClass(Model::class);

        self::assertTrue($alias->isInterface());
        self::assertSame(
            [],
            \array_values(\array_filter(
                $alias->getMethods(),
                static fn(\ReflectionMethod $m): bool => $m->getDeclaringClass()->getName() === Model::class
            )),
            'the alias adds no members of its own — QuickPickHost is the single source of the shape'
        );
        self::assertSame([QuickPickHost::class], $alias->getInterfaceNames());
        self::assertInstanceOf(
            QuickPickHost::class,
            new class implements Model {
                public function update(QuickPick $model, string $msg): array
                {
                    return [$this, $model];
                }

                public function view(QuickPick $model): string
                {
                    return '';
                }
            },
            'a host implementing the deprecated alias satisfies the canonical interface'
        );

        $doc = $alias->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@deprecated', (string) $doc, 'the old name must announce its deprecation');
        self::assertStringContainsString('QuickPickHost', (string) $doc, 'the old name must point at its canonical replacement');
    }

    public function testStatelessHostThreadsEveryKeystrokeThroughTheTuple(): void
    {
        // The -006 cure made executable: this host has NO properties — the
        // object is born empty and stays empty; every state transition
        // arrives and leaves through the return pair.
        $host = new class implements QuickPickHost {
            public function update(QuickPick $model, string $msg): array
            {
                $next = match (true) {
                    $msg === 'down'      => $model->cursorDown(),
                    $msg === 'backspace' => $model->backspace(),
                    $msg === 'enter'     => $model, // host-vocabulary token — the library decodes nothing
                    \str_starts_with($msg, 'type:') => $model->type(\substr($msg, 5)),
                    default              => $model,
                };
                return [$this, $next];
            }

            public function view(QuickPick $model): string
            {
                return $model->view(\str_repeat('.', 40));
            }
        };

        self::assertSame([], (array) $host, 'the host must own zero state for this pin to prove the contract carries it');

        $pick = QuickPick::new(['apple', 'banana', 'cherry'])
            ->withWindowWidth(20)
            ->withWindowHeight(5)
            ->show();

        [$h, $pick] = $host->update($pick, 'type:a');
        self::assertSame($host, $h, 'update() carries the host back');
        self::assertSame(2, $pick->itemCount(), "type:a keeps apple + banana (cherry has no 'a')");

        [$h, $pick] = $host->update($pick, 'down');
        self::assertSame($host, $h);
        self::assertSame(1, $pick->cursor());
        self::assertSame('banana', $pick->selected()?->value());

        [$h, $pick] = $host->update($pick, 'enter');
        self::assertSame('a', $pick->filterText(), 'an unmapped host token is identity, not a reset');

        [$h, $pick] = $host->update($pick, 'backspace');
        self::assertSame('', $pick->filterText());
        self::assertSame(3, $pick->itemCount(), 'the full corpus returns');

        $frame = $host->view($pick);
        self::assertStringContainsString('apple', $frame, 'the loop renders through QuickPick::view()');
        self::assertStringContainsString('─', $frame, 'the separator belongs to the overlay, not the background dots');
    }
}

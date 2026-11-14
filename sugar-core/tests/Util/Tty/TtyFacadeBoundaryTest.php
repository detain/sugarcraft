<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Tty\Backend;

/**
 * Bounds on the {@see Tty} façade (finding sugar-core-067 leg, sc-42:
 * "façade loose bounds").
 *
 * The façade delegates to whichever backend sc-40's single selection truth
 * picks; that delegation is only meaningful while the façade surface and
 * the {@see Backend} contract SURFACE ARE THE SAME SET. A method added to
 * one side without the other is exactly the sc-40 defect class in embryo —
 * an API whose two doors disagree about what the building has. So the
 * central pin here is set equality in BOTH directions, not a subset check:
 * the façade may neither invent nor drop a contract verb.
 *
 * The constant arms hold the re-export honest: Tty documents that callers
 * need not import Backend, which is only true while the re-exported values
 * ARE the contract's values.
 */
final class TtyFacadeBoundaryTest extends TestCase
{
    /** The lifecycle methods the façade is allowed to carry beyond the contract. */
    private const FACADE_LIFECYCLE = ['__construct', '__destruct'];

    public function testTheFaçadeIsFinalAndCarriesNoExtraState(): void
    {
        $facade = new \ReflectionClass(Tty::class);
        $this->assertTrue($facade->isFinal(), 'the backend choice is made once per instance; extending the façade re-opens the two-truths door sc-40 closed');

        foreach ($facade->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), "façade property {$property->getName()} must be private — any protected/public slot lets a subclass or caller steer past the backend");
        }
    }

    public function testTheFaçadeSurfaceEqualsTheContractSurface(): void
    {
        $public = static function (string $class): array {
            $names = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($class === Backend::class || !\in_array($method->getName(), self::FACADE_LIFECYCLE, true)) {
                    $names[] = $method->getName();
                }
            }
            \sort($names);

            return $names;
        };

        // The interface's own declarations, static and instance in one set:
        // the façade's shape must match without caring which side carries
        // the verb, because both spellings are reachable through Tty.
        $contract = [];
        foreach ((new \ReflectionClass(Backend::class))->getMethods() as $method) {
            $contract[] = $method->getName();
        }
        \sort($contract);

        $this->assertSame(
            $contract,
            $public(Tty::class),
            'the Tty façade and the Backend contract must expose the SAME verb set — a name on only one side is an API whose two doors disagree',
        );
    }

    public function testStaticAndInstanceVerbsKeepTheirContractSides(): void
    {
        // Set equality alone would pass if the façade flipped a verb from
        // static to instance (or back): every caller and every backend
        // implements it the other way. Pair the two surfaces verb by verb.
        $byName = static function (string $class): array {
            $shape = [];
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (\in_array($method->getName(), self::FACADE_LIFECYCLE, true)) {
                    continue;
                }
                $shape[$method->getName()] = $method->isStatic() ? 'static' : 'instance';
            }

            return $shape;
        };

        $facade = $byName(Tty::class);
        foreach ($byName(Backend::class) as $verb => $side) {
            $this->assertArrayHasKey($verb, $facade, "contract verb $verb vanished from the façade");
            $this->assertSame($side, $facade[$verb], "$verb changed static-ness between façade and contract");
        }
    }

    public function testTheSignalConstantsAreTheContractValuesReexported(): void
    {
        $this->assertSame(Backend::SIGNAL_INTERRUPT, Tty::SIGNAL_INTERRUPT);
        $this->assertSame(Backend::SIGNAL_RESIZE, Tty::SIGNAL_RESIZE);
        // And not accidentally equal to each other: two bits, two meanings
        // (drainSignals answers a bitmask).
        $this->assertNotSame(Tty::SIGNAL_INTERRUPT, Tty::SIGNAL_RESIZE);
        $this->assertSame(1 | 2, Tty::SIGNAL_INTERRUPT | Tty::SIGNAL_RESIZE, 'the two signal bits must stay single distinct bits for the bitmask arms (WindowsBackend::drainSignals, PosixBackend) to compose');
    }
}

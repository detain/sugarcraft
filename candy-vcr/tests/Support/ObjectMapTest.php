<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Support\ObjectMap;

/**
 * VCR-S26 (finding 109) — every decoded event payload passes through
 * ObjectMap::of(), so the nesting cap is enforced at the single load
 * boundary with a small, documented ceiling and a rejection that names it.
 */
final class ObjectMapTest extends TestCase
{
    public function testShallowPayloadPassesThroughUntouched(): void
    {
        $data = ['b' => 'hello', 'meta' => ['x' => 1, 'y' => ['z' => true]]];

        $this->assertSame($data, ObjectMap::of($data, 'test payload'));
    }

    public function testNestingExactlyAtTheCapIsAccepted(): void
    {
        // ['ok'] is one level; wrap until the chain is MAX_NESTING deep.
        $value = ['ok' => true];
        for ($i = 1; $i < ObjectMap::MAX_NESTING; $i++) {
            $value = ['n' => $value];
        }

        $this->assertSame(['v' => $value], ObjectMap::of(['v' => $value], 'test payload'));
    }

    public function testNestingOneOverTheCapIsRejectedByName(): void
    {
        $value = ['ok' => true];
        for ($i = 0; $i < ObjectMap::MAX_NESTING; $i++) {
            $value = ['n' => $value];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('test payload nests deeper than ' . ObjectMap::MAX_NESTING . ' levels');
        ObjectMap::of(['v' => $value], 'test payload');
    }

    public function testWideShallowDocumentStaysLinearForTheGuard(): void
    {
        // Breadth-first walk visits each node once: 20k tiny sub-arrays
        // must pass fast and without stack growth (a recursive guard would
        // not survive the DEEP case, this one proves it also does not
        // inflate on the wide case).
        $data = [];
        for ($i = 0; $i < 20000; $i++) {
            $data['k' . $i] = ['a' => ['b' => $i]];
        }

        $this->assertCount(20000, ObjectMap::of($data, 'wide payload'));
    }

    public function testIntegerKeysStillFailLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('test payload must be a string-keyed object, integer key 0 given');
        ObjectMap::of([0 => 'x'], 'test payload');
    }
}

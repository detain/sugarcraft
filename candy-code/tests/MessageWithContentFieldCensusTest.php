<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Usage;

/**
 * The PHPD-02 law (reviews-1103): every public field of {@see Message} except
 * `content` survives {@see Message::withContent()}.
 *
 * WHY A CENSUS AND NOT A FIELD LIST: the compaction rescue used to rebuild
 * rows through a hand-enumerated `new Message(...)` inside
 * CompactionService, and the list went stale twice (O-2 thinking rows;
 * C-CTX-6.e recall titles — both recorded in code comments that had to
 * apologize for the drops). The fix — riding Message's single mutate()
 * carrier — is only half a cure: mutate()'s base list is itself hand-written,
 * and nothing pins that it names every constructor parameter. A fixture that
 * ENUMERATES fields would age exactly like the list it replaces.
 *
 * So nothing here enumerates. Every probe value, every comparison and every
 * coverage check below is DERIVED from Message's constructor by reflection.
 * Three arms, one per test method:
 *
 *  - `testEveryProbeDiffersFromItsDeclaredDefault` — each reflection-derived
 *    probe must be distinguishable from what a dropped field would fall back
 *    to, so a silent re-default can never hide behind an equal value (this
 *    is what blinds an all-empty fixture, the exact failure the §7 closeout
 *    comment describes). A future field whose probe generator cannot tell
 *    apart from its own default goes red HERE.
 *  - `testEveryPublicFieldSurvivesAContentSwap` — the behavioral census:
 *    build a Message loaded with those probes, swap only the content, and
 *    assert every non-content field — the row-identity token included — is
 *    identical. A field added tomorrow without a mutate() base-list line
 *    drops to its default here, and the failure names it.
 *  - `testEveryPublicPropertyIsCoveredByTheConstructorCensus` — the
 *    structural arm: every public property of Message must be constructor-
 *    backed. A public property declared outside the constructor is invisible
 *    to `new self(...$namedArgs)` and would be dropped by every wither, so
 *    the census refuses one before it can rot.
 *
 * The companion pin, maintained by the compaction family:
 * `CompactionHidesNotDeletesTest` drives this same copy path end to end
 * through Chat's delegating twin, so the rescue stays behavior-tested while
 * this file owns the carrier law.
 */
final class MessageWithContentFieldCensusTest extends TestCase
{
    /** The one field a content swap is ALLOWED to change. */
    private const SWAPPED_FIELD = 'content';

    /** Not promoted constructor state — read through the accessor instead. */
    private const NON_PROMOTED_PARAM = 'rowKey';

    public function testEveryProbeDiffersFromItsDeclaredDefault(): void
    {
        foreach ($this->constructorParameters() as $parameter) {
            $name = $parameter->getName();
            if ($name === self::SWAPPED_FIELD || $name === self::NON_PROMOTED_PARAM) {
                continue;
            }
            if (!$parameter->isDefaultValueAvailable()) {
                // Required state (role, createdAt) cannot be "dropped to a
                // default" — the named-arg rebuild must carry it or fatal.
                continue;
            }

            $this->assertNotSame(
                $parameter->getDefaultValue(),
                self::probeFor($parameter),
                "the census probe for {$name} equals its declared default — a dropped field would hide; give the probe a distinct value",
            );
        }
    }

    public function testEveryPublicFieldSurvivesAContentSwap(): void
    {
        $probes = [];
        foreach ($this->constructorParameters() as $parameter) {
            $name = $parameter->getName();
            $probes[$name] = match (true) {
                $name === self::SWAPPED_FIELD => 'the original body, long enough to truncate',
                $name === self::NON_PROMOTED_PARAM => null, // never passed: null mints the fresh token
                default => self::probeFor($parameter),
            };
        }

        $original = new Message(...$probes);
        $copy = $original->withContent('the rescued, shorter body');

        $this->assertSame(
            'the rescued, shorter body',
            $copy->content,
            'the swap swapped nothing — withContent() must replace ' . self::SWAPPED_FIELD,
        );

        foreach ($this->constructorParameters() as $parameter) {
            $name = $parameter->getName();
            if ($name === self::SWAPPED_FIELD) {
                continue;
            }

            if ($name === self::NON_PROMOTED_PARAM) {
                // The store keys a live row's identity by this token, so the
                // truncated copy must BE the same row, not a fresh one.
                $this->assertSame(
                    $original->rowKey(),
                    $copy->rowKey(),
                    'withContent() minted a new row identity — the copy is a different row to the store',
                );
                continue;
            }

            $this->assertSame(
                $original->{$name},
                $copy->{$name},
                "withContent() dropped {$name} — mutate()'s base list must carry every constructor field",
            );
        }
    }

    public function testEveryPublicPropertyIsCoveredByTheConstructorCensus(): void
    {
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $this->constructorParameters(),
        );

        $stray = [];
        foreach ((new \ReflectionClass(Message::class))->getProperties() as $property) {
            if ($property->isStatic() || !$property->isPublic()) {
                continue;
            }
            if (!in_array($property->getName(), $names, true)) {
                $stray[] = $property->getName();
            }
        }

        $this->assertSame(
            [],
            $stray,
            'Message gained public state the constructor does not name: new self(...$namedArgs) cannot carry it, so every wither (withContent() included) silently drops it. Promote it, or hide it behind an accessor.',
        );
    }

    /**
     * @return list<\ReflectionParameter>
     */
    private function constructorParameters(): array
    {
        return (new \ReflectionMethod(Message::class, '__construct'))->getParameters();
    }

    /**
     * A reflection-derived probe value that is NOT the declared default of
     * $parameter. Unknown types refuse loudly (an exception, not a skip): a
     * field added tomorrow forces its probe to be registered here, which is
     * the whole point — the census cannot be silently outrun by growth.
     */
    private static function probeFor(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : '?';
        $name = $parameter->getName();

        return match ($typeName) {
            'array' => ['probe-' . $name],
            'string' => 'probe-' . $name,
            'int' => 987_654_321,
            'float' => 0.25,
            'bool' => !($parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : false),
            'SugarCraft\Code\Role' => Role::Assistant,
            'SugarCraft\Code\Usage' => Usage::new(7, 0.25),
            'SugarCraft\Code\Context\Pruning\ContextLedger' => ContextLedger::new(),
            'object' => new \stdClass(),
            default => throw new \RuntimeException(
                "MessageWithContentFieldCensusTest has no probe for {$name} of type {$typeName} —"
                . ' register one so the new field is censused, not skipped',
            ),
        };
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionNamedType;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Host\TranscriptStore;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Message;

/**
 * P-CTX.d: the runner's context snapshot is memory-only, per session, and
 * read-cheap — the same three laws the todo list follows, because the context
 * pane reads this surface per frame exactly like the todo pane does.
 */
final class TurnRunnerContextTest extends TestCase
{
    public function testAHeldNothingReadsNullUntilSomethingIsSaved(): void
    {
        $runner = TurnRunner::new();

        self::assertNull($runner->heldContext('alpha'));
        self::assertNull($runner->heldContext(null), 'the unkeyed bucket starts empty like every other');
    }

    public function testASavedSnapshotReadsBackAsTheSameObject(): void
    {
        $runner = TurnRunner::new();
        $snapshot = self::snapshot(10_000);

        $runner->saveContext('alpha', $snapshot);

        self::assertSame($snapshot, $runner->heldContext('alpha'), 'the pane must not see a re-derivation per frame');
    }

    public function testOneSessionsStampNeverReachesAnother(): void
    {
        $runner = TurnRunner::new();
        $first = self::snapshot(10_000);
        $second = self::snapshot(20_000);

        $runner->saveContext('alpha', $first);
        $runner->saveContext('beta', $second);

        self::assertSame($first, $runner->heldContext('alpha'));
        self::assertSame($second, $runner->heldContext('beta'));
        self::assertNull($runner->heldContext('gamma'), 'an unmeasured session reads nothing, inventing no stamp');
    }

    public function testTheLatestSaveWinsTheSlot(): void
    {
        $runner = TurnRunner::new();
        $stale = self::snapshot(10_000);
        $fresh = self::snapshot(40_000);

        $runner->saveContext('alpha', $stale);
        $runner->saveContext('alpha', $fresh);

        self::assertSame($fresh, $runner->heldContext('alpha'), 'a remeasurement replaces the stamp, never piles up');
    }

    public function testASessionlessSaveLandsInTheEmptyKeyBucket(): void
    {
        $runner = TurnRunner::new();
        $snapshot = self::snapshot(10_000);

        $runner->saveContext(null, $snapshot);

        self::assertSame($snapshot, $runner->heldContext(null));
        self::assertSame($snapshot, $runner->heldContext(''), 'the same slot keyed either way, like heldTodos()');
    }

    /**
     * The memory-only law, pinned structurally: `saveTodos` takes the store
     * because the todo list is durable; `saveContext` must never grow such a
     * parameter — a persisted stamp would outlive the transcript it described.
     */
    public function testTheSaverCarriesNoStoreBecauseTheSnapshotIsNeverPersisted(): void
    {
        $storeTypes = [];
        foreach ((new ReflectionMethod(TurnRunner::class, 'saveContext'))->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $storeTypes[] = $type->getName();
            }
        }

        self::assertNotContains(TranscriptStore::class, $storeTypes);
        self::assertSame([ContextCategories::class], $storeTypes, 'exactly one non-builtin type: the snapshot itself');
    }

    private static function snapshot(int $historyTokens): ContextCategories
    {
        return ContextCategories::from(ContextBreakdown::measure(
            [Message::user('x')],
            null,
            null,
            100_000,
            $historyTokens,
        ));
    }
}

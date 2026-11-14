<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\FocusManager;
use SugarCraft\Dashboard\State\Persistence;
use SugarCraft\Focus\FocusRing;

/**
 * Characterization net for {@see FocusManager}.
 *
 * Written BEFORE recomposing FocusManager onto an internal sugar-focus
 * {@see FocusRing}: these tests pin the pre-existing wrap-around traversal and
 * persistence behavior so the recompose can be proven byte-for-byte identical.
 * The single structural test ({@see testTraversalIsBackedByFocusRing}) is the
 * load-bearing "the dedup is real" assertion — it fails if the delegation to
 * FocusRing is reverted, while every behavioral test stays green either way.
 */
final class FocusManagerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sugar-dashboard-focusmanager-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    /** A manager whose traversal set is [root, a, b, c] in registration order. */
    private function withPanels(): FocusManager
    {
        return (new FocusManager('root'))
            ->register('a')
            ->register('b')
            ->register('c');
    }

    // ═══════════════════════════════════════════════════════════════
    // Initial state
    // ═══════════════════════════════════════════════════════════════

    public function testInitialFocusedIdIsNull(): void
    {
        $fm = new FocusManager('root');
        $this->assertNull($fm->getFocusedId());
    }

    public function testInitialRootIsNotFocusedDespiteBeingRegistered(): void
    {
        // Constructor seeds focusMap[root]=true but leaves focusedId null, so
        // isFocused() — which requires focusedId to match — is false at start.
        $fm = new FocusManager('root');
        $this->assertFalse($fm->isFocused('root'));
    }

    // ═══════════════════════════════════════════════════════════════
    // focus() / blur() / isFocused() / getFocusedId()
    // ═══════════════════════════════════════════════════════════════

    public function testFocusSetsFocusedIdAndIsFocused(): void
    {
        $fm = (new FocusManager('root'))->focus('root');
        $this->assertSame('root', $fm->getFocusedId());
        $this->assertTrue($fm->isFocused('root'));
    }

    public function testFocusReturnsNewInstanceLeavingOriginalUntouched(): void
    {
        $original = new FocusManager('root');
        $focused = $original->focus('root');
        $this->assertNotSame($original, $focused);
        $this->assertNull($original->getFocusedId());
    }

    public function testFocusCoercesIntId(): void
    {
        $fm = (new FocusManager('root'))->register(42)->focus(42);
        $this->assertSame('42', $fm->getFocusedId());
        $this->assertTrue($fm->isFocused('42'));
    }

    public function testBlurClearsFocusWhenBlurringTheFocusedId(): void
    {
        $fm = (new FocusManager('root'))->focus('a')->blur('a');
        $this->assertNull($fm->getFocusedId());
        $this->assertFalse($fm->isFocused('a'));
    }

    public function testBlurOfAnotherIdLeavesFocusIntact(): void
    {
        $fm = (new FocusManager('root'))->register('a')->focus('a')->blur('root');
        $this->assertSame('a', $fm->getFocusedId());
        $this->assertTrue($fm->isFocused('a'));
    }

    public function testIsFocusedRequiresBothFocusedIdAndMapTrue(): void
    {
        // Focusing 'a' then 'b' leaves 'a' in the map as true but no longer the
        // focused id: isFocused('a') must be false.
        $fm = (new FocusManager('root'))->register('a')->register('b')->focus('a')->focus('b');
        $this->assertTrue($fm->isFocused('b'));
        $this->assertFalse($fm->isFocused('a'));
    }

    // ═══════════════════════════════════════════════════════════════
    // register() / unregister()
    // ═══════════════════════════════════════════════════════════════

    public function testRegisterExistingIdIsANoOpReturningSameInstance(): void
    {
        $fm = (new FocusManager('root'))->register('a');
        $this->assertSame($fm, $fm->register('a'));
    }

    public function testRegisterDoesNotChangeFocusedId(): void
    {
        $fm = (new FocusManager('root'))->focus('root')->register('a');
        $this->assertSame('root', $fm->getFocusedId());
    }

    public function testUnregisterFocusedIdClearsFocus(): void
    {
        $fm = (new FocusManager('root'))->register('a')->focus('a')->unregister('a');
        $this->assertNull($fm->getFocusedId());
    }

    public function testUnregisterNonFocusedIdLeavesFocusIntact(): void
    {
        $fm = (new FocusManager('root'))->register('a')->focus('root')->unregister('a');
        $this->assertSame('root', $fm->getFocusedId());
    }

    // ═══════════════════════════════════════════════════════════════
    // focusNext() wrap-around (traversal set [root, a, b, c])
    // ═══════════════════════════════════════════════════════════════

    public function testFocusNextFromNullFocusesFirstRegistered(): void
    {
        $fm = $this->withPanels()->focusNext();
        $this->assertSame('root', $fm->getFocusedId());
    }

    public function testFocusNextAdvancesThroughRegistrationOrder(): void
    {
        $fm = $this->withPanels()->focus('root');
        $fm = $fm->focusNext();
        $this->assertSame('a', $fm->getFocusedId());
        $fm = $fm->focusNext();
        $this->assertSame('b', $fm->getFocusedId());
        $fm = $fm->focusNext();
        $this->assertSame('c', $fm->getFocusedId());
    }

    public function testFocusNextWrapsPastTheEnd(): void
    {
        $fm = $this->withPanels()->focus('c')->focusNext();
        $this->assertSame('root', $fm->getFocusedId());
    }

    public function testFocusNextOnSingleElementStaysOnIt(): void
    {
        $fm = (new FocusManager('root'))->focus('root')->focusNext();
        $this->assertSame('root', $fm->getFocusedId());
    }

    public function testFocusNextFromNullOnSingleElementFocusesIt(): void
    {
        $fm = (new FocusManager('root'))->focusNext();
        $this->assertSame('root', $fm->getFocusedId());
    }

    // ═══════════════════════════════════════════════════════════════
    // focusPrevious() wrap-around
    // ═══════════════════════════════════════════════════════════════

    public function testFocusPreviousFromNullFocusesLastRegistered(): void
    {
        $fm = $this->withPanels()->focusPrevious();
        $this->assertSame('c', $fm->getFocusedId());
    }

    public function testFocusPreviousStepsBackwards(): void
    {
        $fm = $this->withPanels()->focus('b');
        $fm = $fm->focusPrevious();
        $this->assertSame('a', $fm->getFocusedId());
        $fm = $fm->focusPrevious();
        $this->assertSame('root', $fm->getFocusedId());
    }

    public function testFocusPreviousWrapsPastTheStart(): void
    {
        $fm = $this->withPanels()->focus('root')->focusPrevious();
        $this->assertSame('c', $fm->getFocusedId());
    }

    public function testFocusPreviousOnSingleElementStaysOnIt(): void
    {
        $fm = (new FocusManager('root'))->focus('root')->focusPrevious();
        $this->assertSame('root', $fm->getFocusedId());
    }

    // ═══════════════════════════════════════════════════════════════
    // Traversal with an empty set (root unregistered)
    // ═══════════════════════════════════════════════════════════════

    public function testFocusNextOnEmptySetIsANoOp(): void
    {
        $fm = (new FocusManager('root'))->unregister('root');
        $next = $fm->focusNext();
        $this->assertNull($next->getFocusedId());
    }

    public function testFocusPreviousOnEmptySetIsANoOp(): void
    {
        $fm = (new FocusManager('root'))->unregister('root');
        $prev = $fm->focusPrevious();
        $this->assertNull($prev->getFocusedId());
    }

    // ═══════════════════════════════════════════════════════════════
    // Registration is the only door to the traversal set (finding 127)
    // — DISCLOSED contract flip: this file once pinned that focus()/
    // blur() on unregistered ids EXTENDED the set; that WAS the bug.
    // ═══════════════════════════════════════════════════════════════

    public function testFocusOnUnregisteredIdIsRefused(): void
    {
        $fm = (new FocusManager('root'))->register('a');
        $after = $fm->focus('x');
        $this->assertSame($fm, $after, 'focus() must not move state for an unregistered id');
        $this->assertNull($after->focusedId(), 'a refused focus leaves the slot empty, never names a phantom');
        // The traversal set never learned about 'x': Tab walks root -> a -> root.
        $walk = $after->focusNext()->focusNext()->focusNext();
        $this->assertSame('root', $walk->focusedId());
    }

    public function testNegativeNumericFocusCannotMintAPhantomSlot(): void
    {
        $fm = (new FocusManager('root'))->register('a');
        $this->assertSame($fm, $fm->focus(-1), 'focus(-1) must be refused, not stored as the id "-1"');
        $this->assertNull($fm->focusedId());
        // And the ring never grew: only root and 'a' exist.
        $probe = $fm->focusNext()->focusNext()->focusNext();
        $this->assertSame('root', $probe->focusedId());
    }

    public function testBlurOnUnregisteredIdIsANoOp(): void
    {
        $fm = (new FocusManager('root'))->register('a');
        $this->assertSame($fm, $fm->blur('y'), 'blur must never seed a ghost tab-stop');
        // The set the old shape grew via blur stays closed: two members means
        // the third Tab lands back on root; a ghost 'y' would land on 'y'.
        $walk = $fm->focusNext()->focusNext()->focusNext();
        $this->assertSame('root', $walk->focusedId());
        $this->assertFalse($walk->isFocused('y'), 'blur must not register anything');
    }

    // ═══════════════════════════════════════════════════════════════
    // Fail-soft restore (finding 127): corrupt state is skipped or
    // repaired with a reason, never a TypeError through init.
    // ═══════════════════════════════════════════════════════════════

    public function testRestoreSkipsCorruptMapEntriesAndReportsReasons(): void
    {
        $persistence = new Persistence();
        $path = $this->tmpDir . '/focus-corrupt.json';
        $persistence->save($path, [
            'focusedId' => 'ghost',
            'focusMap' => ['a' => true, 'b' => 'yes', 'c' => 1],
        ]);

        $restored = (new FocusManager('root'))->restoreState($persistence, $path);

        $notice = $restored->restoreNotice();
        $this->assertNotNull($notice);
        $this->assertStringStartsWith('restore repaired:', $notice);
        $this->assertStringContainsString("entry 'b' is not a bool", $notice);
        $this->assertStringContainsString("entry 'c' is not a bool", $notice);
        $this->assertStringContainsString('focusedId is not a registered panel', $notice);
        $this->assertNull($restored->focusedId(), 'the ghost focusedId must be dropped, not trusted');
        $this->assertFalse($restored->isFocused('a'), 'restored registration is not focus; the double gate holds');
        // Survivors traverse; victims are gone: the set is exactly ['a'].
        $this->assertSame('a', $restored->focusNext()->focusedId());
    }

    public function testRestoreOfUnsupportedVersionSkipsWithHonestNoticeInsteadOfThrowing(): void
    {
        $persistence = new Persistence();
        $path = $this->tmpDir . '/focus-future.json';
        file_put_contents(
            $path,
            (string) json_encode(['version' => 2, 'data' => ['focusedId' => 'a', 'focusMap' => ['a' => true]]])
        );

        $fm = new FocusManager('root');
        $restored = $fm->restoreState($persistence, $path);

        $this->assertSame('restore skipped: Unsupported persistence version: 2', $restored->restoreNotice());
        $this->assertNull($restored->focusedId(), 'the untouched manager keeps its constructor state');
        $this->assertSame('root', $restored->focusNext()->focusedId(), 'root-only ring survives');
    }

    public function testRestoreWithNonArrayFocusMapSkipsCleanly(): void
    {
        $persistence = new Persistence();
        $path = $this->tmpDir . '/focus-shapeless.json';
        $persistence->save($path, ['focusedId' => 'a', 'focusMap' => 'not-an-object']);

        $restored = (new FocusManager('root'))->restoreState($persistence, $path);

        $this->assertStringStartsWith('restore skipped: focusMap is not an object', (string) $restored->restoreNotice());
        $this->assertNull($restored->focusedId());
        $this->assertSame('root', $restored->focusNext()->focusedId());
    }

    // ═══════════════════════════════════════════════════════════════
    // Persistence round-trip
    // ═══════════════════════════════════════════════════════════════

    public function testPersistAndRestoreRoundTrip(): void
    {
        $persistence = new Persistence();
        $path = $this->tmpDir . '/focus.json';

        $saved = $this->withPanels()->focus('b');
        $saved->persistState($persistence, $path);

        $restored = (new FocusManager('root'))->restoreState($persistence, $path);

        $this->assertSame('b', $restored->getFocusedId());
        $this->assertTrue($restored->isFocused('b'));
    }

    public function testRestorePreservesTraversalOrder(): void
    {
        $persistence = new Persistence();
        $path = $this->tmpDir . '/focus-order.json';

        $this->withPanels()->focus('root')->persistState($persistence, $path);

        $restored = (new FocusManager('root'))->restoreState($persistence, $path);

        $restored = $restored->focusNext();
        $this->assertSame('a', $restored->getFocusedId());
        $restored = $restored->focusNext();
        $this->assertSame('b', $restored->getFocusedId());
    }

    public function testRestoreWithNoPersistedStateReturnsSelf(): void
    {
        $persistence = new Persistence();
        $fm = new FocusManager('root');
        $restored = $fm->restoreState($persistence, $this->tmpDir . '/does-not-exist.json');
        $this->assertSame($fm, $restored);
    }

    // ═══════════════════════════════════════════════════════════════
    // Structural: the dedup is real (load-bearing)
    // ═══════════════════════════════════════════════════════════════

    public function testTraversalIsBackedByFocusRing(): void
    {
        // This is the load-bearing "dedup is real" assertion: FocusManager holds
        // an internal sugar-focus FocusRing that owns the ordered traversal set.
        // Reverting the recompose (hand-rolled modulo wrap) drops this property
        // and fails here, while every behavioral test above stays green.
        $fm = $this->withPanels();

        $ringProp = null;
        $ref = new \ReflectionObject($fm);
        foreach ($ref->getProperties() as $prop) {
            $prop->setAccessible(true);
            $value = $prop->getValue($fm);
            if ($value instanceof FocusRing) {
                $ringProp = $value;
                break;
            }
        }

        $this->assertInstanceOf(
            FocusRing::class,
            $ringProp,
            'FocusManager must delegate traversal to an internal SugarCraft\\Focus\\FocusRing',
        );
        // The ring mirrors the registration order that drives traversal.
        $this->assertSame(['root', 'a', 'b', 'c'], $ringProp->ids());
    }
}

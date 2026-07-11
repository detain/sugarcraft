<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\FocusManager;
use SugarCraft\Dash\State\Persistence;
use SugarCraft\Focus\FocusRing;

/**
 * Characterization net for {@see FocusManager}.
 *
 * Written BEFORE recomposing FocusManager onto an internal candy-focus
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
        $this->tmpDir = sys_get_temp_dir() . '/sugar-dash-focusmanager-test-' . bin2hex(random_bytes(4));
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
        $fm = (new FocusManager('root'))->focus(42);
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
    // focus()/blur() on unregistered ids extend the traversal set
    // ═══════════════════════════════════════════════════════════════

    public function testFocusOnUnregisteredIdAddsItToTraversal(): void
    {
        // focus('x') on a fresh manager adds 'x' after 'root'; wrapping from 'x'
        // lands back on 'root'.
        $fm = (new FocusManager('root'))->focus('x');
        $this->assertSame('x', $fm->getFocusedId());
        $wrapped = $fm->focusNext();
        $this->assertSame('root', $wrapped->getFocusedId());
    }

    public function testBlurOnUnregisteredIdAddsItToTraversal(): void
    {
        // blur('y') seeds 'y' (disabled-bool false) at the end of the set; it
        // still participates in traversal ordering.
        $fm = (new FocusManager('root'))->blur('y')->focus('root');
        $next = $fm->focusNext();
        $this->assertSame('y', $next->getFocusedId());
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
        // an internal candy-focus FocusRing that owns the ordered traversal set.
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

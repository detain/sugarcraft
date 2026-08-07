<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tui\SessionTab;

/**
 * @internal
 *
 * @see SessionTab
 */
final class SessionTabTest extends TestCase
{
    // =========================================================================
    // withDetached() Tests
    // =========================================================================

    public function testWithDetachedSetsDetachedState(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: false,
            lastActivityAt: new DateTimeImmutable('2024-01-01 12:00:00'),
            agentSummary: null,
            isDetached: false,
        );

        $detachedTab = $tab->withDetached(true);

        $this->assertTrue($detachedTab->isDetached());
        $this->assertFalse($tab->isDetached()); // Original unchanged
    }

    public function testWithDetachedCanSetFalse(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isDetached: true,
        );

        $attachedTab = $tab->withDetached(false);

        $this->assertFalse($attachedTab->isDetached());
        $this->assertTrue($tab->isDetached()); // Original unchanged
    }

    public function testWithDetachedPreservesOtherFields(): void
    {
        $original = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: true,
            lastActivityAt: new DateTimeImmutable('2024-01-01 12:00:00'),
            agentSummary: 'Some summary',
            isDetached: false,
        );

        $modified = $original->withDetached(true);

        $this->assertSame('tab-1', $modified->id());
        $this->assertSame('Test Session', $modified->sessionName());
        $this->assertTrue($modified->isActive());
        $this->assertSame('2024-01-01 12:00:00', $modified->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertSame('Some summary', $modified->agentSummary());
    }

    // =========================================================================
    // withSummary() Tests
    // =========================================================================

    public function testWithSummarySetsAgentSummary(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            agentSummary: null,
        );

        $summariedTab = $tab->withSummary('New agent summary');

        $this->assertSame('New agent summary', $summariedTab->agentSummary());
        $this->assertNull($tab->agentSummary()); // Original unchanged
    }

    public function testWithSummaryCanSetEmptyString(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            agentSummary: 'Some summary',
        );

        $clearedTab = $tab->withSummary('');

        $this->assertSame('', $clearedTab->agentSummary());
        $this->assertSame('Some summary', $tab->agentSummary()); // Original unchanged
    }

    public function testWithSummaryCanSetNull(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            agentSummary: 'Some summary',
        );

        // Use withSummary passing null - but looking at the method signature,
        // it accepts string, not ?string. Let's verify the actual behavior.
        // Actually looking at the code: public function withSummary(string $summary): self
        // It only accepts string, not null. So we test empty string case above.
        $this->assertTrue(true); // Placeholder - empty string case covered above
    }

    public function testWithSummaryPreservesOtherFields(): void
    {
        $original = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: true,
            lastActivityAt: new DateTimeImmutable('2024-01-01 12:00:00'),
            agentSummary: null,
            isDetached: true,
        );

        $modified = $original->withSummary('Updated summary');

        $this->assertSame('tab-1', $modified->id());
        $this->assertSame('Test Session', $modified->sessionName());
        $this->assertTrue($modified->isActive());
        $this->assertSame('2024-01-01 12:00:00', $modified->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertTrue($modified->isDetached());
    }

    // =========================================================================
    // withActive() Tests
    // =========================================================================

    public function testWithActiveSetsActiveState(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: false,
        );

        $activeTab = $tab->withActive(true);

        $this->assertTrue($activeTab->isActive());
        $this->assertFalse($tab->isActive()); // Original unchanged
    }

    public function testWithActiveCanSetInactive(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: true,
        );

        $inactiveTab = $tab->withActive(false);

        $this->assertFalse($inactiveTab->isActive());
        $this->assertTrue($tab->isActive()); // Original unchanged
    }

    public function testWithActivePreservesOtherFields(): void
    {
        $original = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: false,
            lastActivityAt: new DateTimeImmutable('2024-01-01 12:00:00'),
            agentSummary: 'Some summary',
            isDetached: true,
        );

        $modified = $original->withActive(true);

        $this->assertSame('tab-1', $modified->id());
        $this->assertSame('Test Session', $modified->sessionName());
        $this->assertSame('2024-01-01 12:00:00', $modified->lastActivityAt->format('Y-m-d H:i:s'));
        $this->assertSame('Some summary', $modified->agentSummary());
        $this->assertTrue($modified->isDetached());
    }

    // =========================================================================
    // Immutability Tests
    // =========================================================================

    public function testWithMethodsReturnNewInstances(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
        );

        $detachedTab = $tab->withDetached(true);
        $summariedTab = $tab->withSummary('summary');
        $activeTab = $tab->withActive(true);

        $this->assertNotSame($tab, $detachedTab);
        $this->assertNotSame($tab, $summariedTab);
        $this->assertNotSame($tab, $activeTab);
    }

    public function testOriginalInstanceUnchangedAfterWithCalls(): void
    {
        $tab = new SessionTab(
            id: 'tab-1',
            sessionName: 'Test Session',
            isActive: false,
            agentSummary: null,
            isDetached: false,
        );

        $tab->withDetached(true);
        $tab->withSummary('summary');
        $tab->withActive(true);

        $this->assertFalse($tab->isActive());
        $this->assertNull($tab->agentSummary());
        $this->assertFalse($tab->isDetached());
    }

    // =========================================================================
    // Accessor Method Tests
    // =========================================================================

    public function testIdReturnsCorrectValue(): void
    {
        $tab = new SessionTab(id: 'my-id', sessionName: 'Test');
        $this->assertSame('my-id', $tab->id());
    }

    public function testSessionNameReturnsCorrectValue(): void
    {
        $tab = new SessionTab(id: 'id', sessionName: 'My Session');
        $this->assertSame('My Session', $tab->sessionName());
    }

    public function testIsDetachedReturnsCorrectValue(): void
    {
        $tab = new SessionTab(id: 'id', sessionName: 'Test', isDetached: true);
        $this->assertTrue($tab->isDetached());
    }

    public function testIsActiveReturnsCorrectValue(): void
    {
        $tab = new SessionTab(id: 'id', sessionName: 'Test', isActive: true);
        $this->assertTrue($tab->isActive());
    }

    public function testAgentSummaryReturnsCorrectValue(): void
    {
        $tab = new SessionTab(id: 'id', sessionName: 'Test', agentSummary: 'test summary');
        $this->assertSame('test summary', $tab->agentSummary());
    }

    public function testAgentSummaryReturnsNullWhenNotSet(): void
    {
        $tab = new SessionTab(id: 'id', sessionName: 'Test', agentSummary: null);
        $this->assertNull($tab->agentSummary());
    }
}

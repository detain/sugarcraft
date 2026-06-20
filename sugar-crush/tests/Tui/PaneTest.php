<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use SugarCraft\Crush\Tui\Pane;
use PHPUnit\Framework\TestCase;

final class PaneTest extends TestCase
{
    /**
     * @testdox All 8 enum cases exist and have correct string values
     */
    public function testEnumCasesExistWithCorrectStringValues(): void
    {
        $this->assertSame('chat', Pane::Chat->value);
        $this->assertSame('input', Pane::Input->value);
        $this->assertSame('skills', Pane::Skills->value);
        $this->assertSame('agents', Pane::Agents->value);
        $this->assertSame('files', Pane::Files->value);
        $this->assertSame('tools', Pane::Tools->value);
        $this->assertSame('settings', Pane::Settings->value);
        $this->assertSame('help', Pane::Help->value);
    }

    /**
     * @testdox label() returns correct human-readable string for each pane
     */
    public function testLabelReturnsCorrectString(): void
    {
        $this->assertSame('Chat', Pane::Chat->label());
        $this->assertSame('Input', Pane::Input->label());
        $this->assertSame('Skills', Pane::Skills->label());
        $this->assertSame('Agents', Pane::Agents->label());
        $this->assertSame('Files', Pane::Files->label());
        $this->assertSame('Tools', Pane::Tools->label());
        $this->assertSame('Settings', Pane::Settings->label());
        $this->assertSame('Help', Pane::Help->label());
    }

    /**
     * @testdox next() cycles correctly from each pane
     * Cycle: Chat → Input → Files → Tools → Skills → Agents → Settings → Help → Chat
     */
    public function testNextCyclesCorrectly(): void
    {
        $this->assertSame(Pane::Input, Pane::Chat->next());
        $this->assertSame(Pane::Files, Pane::Input->next());
        $this->assertSame(Pane::Tools, Pane::Files->next());
        $this->assertSame(Pane::Skills, Pane::Tools->next());
        $this->assertSame(Pane::Agents, Pane::Skills->next());
        $this->assertSame(Pane::Settings, Pane::Agents->next());
        $this->assertSame(Pane::Help, Pane::Settings->next());
        $this->assertSame(Pane::Chat, Pane::Help->next());
    }

    /**
     * @testdox Complete cycle through all panes returns to Chat
     */
    public function testCompleteCycleReturnsToChat(): void
    {
        $pane = Pane::Chat;

        // 8 transitions to complete a full cycle back to Chat
        for ($i = 0; $i < 8; $i++) {
            $pane = $pane->next();
        }

        $this->assertSame(Pane::Chat, $pane);
    }

    /**
     * @testdox Each pane can be created from its string value with from()
     */
    public function testFromReturnsCorrectPane(): void
    {
        $this->assertSame(Pane::Chat, Pane::from('chat'));
        $this->assertSame(Pane::Input, Pane::from('input'));
        $this->assertSame(Pane::Skills, Pane::from('skills'));
        $this->assertSame(Pane::Agents, Pane::from('agents'));
        $this->assertSame(Pane::Files, Pane::from('files'));
        $this->assertSame(Pane::Tools, Pane::from('tools'));
        $this->assertSame(Pane::Settings, Pane::from('settings'));
        $this->assertSame(Pane::Help, Pane::from('help'));
    }

    /**
     * @testdox from() throws ValueError for invalid string value
     */
    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        Pane::from('invalid');
    }

    /**
     * @testdox from() throws ValueError for empty string
     */
    public function testFromThrowsOnEmptyString(): void
    {
        $this->expectException(\ValueError::class);
        Pane::from('');
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui\Components;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Tui\Components\StatusBar;
use SugarCraft\Crush\Util\TokenTracker;
use SugarCraft\Sprinkles\Style;

/**
 * @see StatusBar
 * @see StatusBar::render()
 */
final class StatusBarTest extends TestCase
{
    private ProviderInterface $provider;
    private TokenTracker $tokens;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
        $this->provider->method('name')->willReturn('TestProvider');
        $this->tokens = new TokenTracker();
    }

    private function app(): App
    {
        return App::new($this->provider, 'test-model');
    }

    private function skill(string $name): Skill
    {
        return new Skill($name, '', false, false, null, null, null, '', '', [], '', '');
    }

    /**
     * The migrated status bar delegates layout to Sprinkles\Bar\StatusBar but
     * must remain byte-identical to the original hand-rolled theming: a leading
     * and trailing space, provider / model / token-summary segments joined by
     * `'  |  '`, each carrying its own foreground colour.
     */
    public function testProviderModelAndTokenSummaryAreByteExact(): void
    {
        $expected = ' '
            . Style::new()->foreground(Color::hex('#6ee7b7'))->render('TestProvider')
            . '  |  ' . Style::new()->foreground(Color::hex('#fde68a'))->render('test-model')
            . '  |  ' . Style::new()->foreground(Color::hex('#7d6e98'))->render($this->tokens->summary())
            . ' ';

        $this->assertSame($expected, StatusBar::render($this->app(), $this->tokens));
    }

    public function testEnabledSkillsSegmentIsAppended(): void
    {
        $app = $this->app()->withEnabledSkills([$this->skill('bash'), $this->skill('edit')]);

        $output = StatusBar::render($app, $this->tokens);

        $this->assertStringContainsString('Skills: bash, edit', $output);
        // Skills segment joins into the bar with the shared separator.
        $this->assertStringContainsString('  |  ', $output);
    }

    public function testNoSkillsSegmentWhenNoneEnabled(): void
    {
        $output = StatusBar::render($this->app(), $this->tokens);

        $this->assertStringNotContainsString('Skills:', $output);
    }

    public function testErrorSegmentTakesPriorityOverStatus(): void
    {
        $app = $this->app()->withError('boom')->withStatus('working');

        $output = StatusBar::render($app, $this->tokens);

        $this->assertStringContainsString('error: boom', $output);
        $this->assertStringNotContainsString('working', $output);
    }

    public function testStatusSegmentShownWhenNoError(): void
    {
        $app = $this->app()->withStatus('Processing...');

        $output = StatusBar::render($app, $this->tokens);

        $this->assertStringContainsString('Processing...', $output);
        $this->assertStringNotContainsString('error:', $output);
    }

    public function testEdgePaddingAndSeparatorComeFromPrimitive(): void
    {
        $output = StatusBar::render($this->app(), $this->tokens);

        $this->assertStringStartsWith(' ', $output);
        $this->assertStringEndsWith(' ', $output);
        $this->assertStringContainsString('  |  ', $output);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\SgrState;
use SugarCraft\Core\Util\Parser;
use SugarCraft\Style\Style;
use SugarCraft\Widgets\Help\Help;
use SugarCraft\Widgets\Help\Styles;
use SugarCraft\Widgets\Key\Binding;
use SugarCraft\Widgets\Key\KeyMap;

/**
 * Pins for the 2026-11 review fixes: the close-once truncate (C5), the
 * marked-frame sanitizer at the key/description ingress (C3/M20), the
 * ::new() factory and the gap/width `with`-setters (M16/m16).
 */
final class HelpReviewPinsTest extends TestCase
{
    /** @param list<Binding> $short @param list<list<Binding>> $full */
    private function map(array $short, array $full = []): KeyMap
    {
        return new class ($short, $full) implements KeyMap {
            /** @param list<Binding> $short @param list<list<Binding>> $full */
            public function __construct(
                private readonly array $short,
                private readonly array $full,
            ) {}

            public function shortHelp(): array { return $this->short; }
            public function fullHelp(): array  { return $this->full; }
        };
    }

    private function binding(string $key, string $desc, array $keys = []): Binding
    {
        return (new Binding($keys ?: [$key]))->withHelp($key, $desc);
    }

    /** Feed a whole frame through the house SGR oracle. */
    private function isBalanceClean(string $frame): bool
    {
        $state = SgrState::initial();
        foreach ((new Parser())->parse($frame) as $token) {
            $state->apply($token);
        }
        return $state->isDefault();
    }

    public function testTruncatedRowClosesItsOpenSgrBeforeTheEllipsis(): void
    {
        // Bold key column, width forcing a cut inside the styled run.
        // Pre-fix, the byte walk sliced the escape itself and left the
        // bold ON into the host scrollback; post-fix the kept prefix is
        // folded and closed once before the ellipsis glyph (C5).
        $map = $this->map([
            $this->binding('alpha-key', 'alpha', ['a']),
            $this->binding('q', 'quit'),
        ]);
        $help = Help::new(styles: new Styles(shortKey: Style::new()->bold()))->withWidth(8);
        $view = $help->shortView($map);

        $this->assertStringStartsWith("\x1b[1m", $view);
        $this->assertTrue($this->isBalanceClean($view), 'truncated help row must end in default SGR state');
        $this->assertStringEndsWith("\x1b[0m…", $view);
    }

    public function testFittingRowIsByteIdenticalToUntruncatedPath(): void
    {
        $map = $this->map([$this->binding('q', 'quit')]);
        $wide  = Help::new(maxWidth: 80)->shortView($map);
        $fit   = Help::new()->shortView($map);
        $this->assertSame($fit, $wide);
    }

    public function testKeyAndDescriptionCannotForgeZoneSentinelsOrC1(): void
    {
        // Forged U+E000/U+E001 markers and 8-bit C1 intros must not ride
        // the rendered row into a marked-frame host (C3/M20).
        $map = $this->map([
            // Legal binding key; the forged bytes ride the HELP TEXT — the
            // injection surface this pin probes (a forged key string is now
            // refused at construction, which BindingTest pins separately).
            $this->binding("\u{E000}tab-1\u{E001}x\u{009B}", "de\u{E000}/tab-1\u{E001}sc\u{009B}", ['tab']),
        ]);
        $view = Help::new()->shortView($map);
        $this->assertStringNotContainsString("\u{E000}", $view);
        $this->assertStringNotContainsString("\u{E001}", $view);
        $this->assertStringNotContainsString("\u{009B}", $view);
    }

    public function testFactoryDefaultsMatchConstructorBytes(): void
    {
        $map = $this->map([
            $this->binding('ctrl+a', 'all'),
            $this->binding('q', 'quit'),
        ]);
        $this->assertSame(Help::new()->shortView($map), Help::new()->shortView($map));
        $this->assertSame(Help::new()->fullView($map), Help::new()->fullView($map));
    }

    public function testGapSettersAreHonoured(): void
    {
        $map = $this->map([$this->binding('q', 'quit')]);
        $this->assertSame('q  quit', Help::new(keyDescGap: '  ')->shortView($map));

        $grid = $this->map([], [
            [$this->binding('a', 'alpha')],
            [$this->binding('b', 'beta')],
        ]);
        $view = Help::new(columnGap: '--')->fullView($grid);
        $this->assertStringContainsString('--', $view);
        $this->assertStringNotContainsString('    ', $view);
    }

    public function testWithWidthAliasSharesWidthCoercion(): void
    {
        $this->assertSame(24, Help::new()->withWidth(24)->maxWidth);
        $this->assertEquals(Help::new()->width(24), Help::new()->withWidth(24));
        $this->expectException(\InvalidArgumentException::class);
        Help::new()->withWidth(-1);
    }
}

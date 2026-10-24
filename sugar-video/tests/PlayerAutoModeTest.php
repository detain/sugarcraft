<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Render\RendererFactory;
use SugarCraft\Video\Synthetic;
use SugarCraft\Video\Tests\Concerns\HidesPathBinaries;

/**
 * The auto-detect default on Player::open() (docs-fix-1023 video M1).
 *
 * The README always promised capability auto-detection at the Player entry
 * point, but open() shipped a hard `Mode::HalfBlock` default — every caller
 * relying on it rendered half-block regardless of what the terminal could
 * actually do. The default now resolves through the SAME ladder Reel uses
 * (RendererFactory::autoMode()), and the $autoModeChooser seam injects the
 * resolved mode so these pins stay hermetic no matter what TERM/COLORTERM
 * the CI host carries.
 */
final class PlayerAutoModeTest extends TestCase
{
    use HidesPathBinaries;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->files = [];
        parent::tearDown();
    }

    private function gif(): string
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $path = Synthetic::generate(
            sys_get_temp_dir() . '/sugar-video-automode-' . getmypid() . '.gif',
            16,
            8,
            4,
            4,
        );
        $this->files[] = $path;

        return $path;
    }

    public function testTheChooserSeamResolvesKittyAtTheBoundary(): void
    {
        $player = $this->withoutPathBinaries(
            fn (): Player => Player::open(
                $this->gif(),
                8,
                4,
                autoModeChooser: static fn (): Mode => Mode::Kitty,
            ),
        );

        try {
            $this->assertSame(Mode::Kitty, $player->mode, 'the boundary must honour the resolved mode, not the historical constant');
        } finally {
            $player->stop();
        }
    }

    public function testTheChooserSeamResolvesSixelAtTheBoundary(): void
    {
        $player = $this->withoutPathBinaries(
            fn (): Player => Player::open(
                $this->gif(),
                8,
                4,
                autoModeChooser: static fn (): Mode => Mode::Sixel,
            ),
        );

        try {
            $this->assertSame(Mode::Sixel, $player->mode);
        } finally {
            $player->stop();
        }
    }

    public function testAnExplicitModeIsCarriedThroughUntouched(): void
    {
        // Consumers that pass Mode::HalfBlock explicitly — the pre-fix value
        // of the default — must keep exactly the old behaviour: the boundary
        // only resolves a NULL mode.
        $player = $this->withoutPathBinaries(
            fn (): Player => Player::open($this->gif(), 8, 4, mode: Mode::HalfBlock),
        );

        try {
            $this->assertSame(Mode::HalfBlock, $player->mode);
        } finally {
            $player->stop();
        }
    }

    public function testTheLiveDefaultAnswersWhatRendererFactoryResolves(): void
    {
        // With no chooser the boundary defers to the same live ladder Reel
        // uses. Comparing live-against-live keeps the pin environment-proof
        // while still reddening a default that re-hardcodes a constant.
        $expected = RendererFactory::autoMode();

        $player = $this->withoutPathBinaries(
            fn (): Player => Player::open($this->gif(), 8, 4),
        );

        try {
            $this->assertSame($expected, $player->mode, 'the unseeded default must equal the Reel ladder answer');
        } finally {
            $player->stop();
        }
    }

    public function testChooserResolvedHalfBlockRendersByteIdenticalToExplicitHalfBlock(): void
    {
        // The old output, pinned: a caller that resolves to HalfBlock through
        // the new boundary sees EXACTLY the frame bytes a pre-fix default
        // produced — resolution changed which mode is chosen, never how a
        // chosen mode paints.
        $gif = $this->gif();

        $explicit = $this->withoutPathBinaries(
            fn (): Player => Player::open($gif, 8, 4, mode: Mode::HalfBlock),
        );
        $resolved = $this->withoutPathBinaries(
            fn (): Player => Player::open(
                $gif,
                8,
                4,
                autoModeChooser: static fn (): Mode => Mode::HalfBlock,
            ),
        );

        try {
            $a = $explicit->seekToSeconds(0.1)->view();
            $b = $resolved->seekToSeconds(0.1)->view();

            $this->assertNotSame('', $a, 'the frame path must actually render, not emit the placeholder');
            $this->assertStringNotContainsString('placeholder', $a);
            $this->assertSame($a, $b, 'boundary resolution must not perturb HalfBlock output');
        } finally {
            $explicit->stop();
            $resolved->stop();
        }
    }
}

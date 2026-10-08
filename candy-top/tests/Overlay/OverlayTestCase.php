<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Overlay;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Overlay\Overlay;
use SugarCraft\Top\Overlay\OverlayContext;
use SugarCraft\Top\Overlay\OverlayResult;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\Ink;

/**
 * Shared drivers for the overlay unit tests: a context at a given size,
 * btop-named keys as KeyMsgs and 0-based clicks as MouseMsgs.
 */
abstract class OverlayTestCase extends TestCase
{
    protected function setUp(): void
    {
        T::reset();
    }

    protected static function ctx(int $cols = 120, int $rows = 40, ?Config $config = null): OverlayContext
    {
        return new OverlayContext($config ?? Config::new(), $cols, $rows, Ink::new(ThemeConfig::new()));
    }

    protected static function key(string $name): KeyMsg
    {
        return match ($name) {
            'up' => new KeyMsg(KeyType::Up),
            'down' => new KeyMsg(KeyType::Down),
            'left' => new KeyMsg(KeyType::Left),
            'right' => new KeyMsg(KeyType::Right),
            'enter' => new KeyMsg(KeyType::Enter),
            'escape' => new KeyMsg(KeyType::Escape),
            'backspace' => new KeyMsg(KeyType::Backspace),
            'space' => new KeyMsg(KeyType::Space, ' '),
            'tab' => new KeyMsg(KeyType::Tab),
            'shift_tab' => new KeyMsg(KeyType::Tab, shift: true),
            'page_down' => new KeyMsg(KeyType::PageDown),
            'page_up' => new KeyMsg(KeyType::PageUp),
            default => new KeyMsg(KeyType::Char, $name),
        };
    }

    /** A bare left click on 0-based cell ($x, $y). */
    protected static function click(int $x, int $y): MouseMsg
    {
        return new MouseMsg($x + 1, $y + 1, MouseButton::Left, MouseAction::Press);
    }

    protected static function wheel(bool $down): MouseMsg
    {
        return new MouseMsg(1, 1, $down ? MouseButton::WheelDown : MouseButton::WheelUp, MouseAction::Press);
    }

    /** Feed `$keys` (names) to `$overlay`, keeping it open; returns the last result. */
    protected static function feed(Overlay $overlay, array $keys, ?OverlayContext $ctx = null): OverlayResult
    {
        $ctx ??= self::ctx();
        $result = OverlayResult::keep($overlay);
        foreach ($keys as $k) {
            self::assertNotNull($result->overlay, 'still open before ' . $k);
            $result = $result->overlay->update(self::key($k), $ctx);
        }

        return $result;
    }
}

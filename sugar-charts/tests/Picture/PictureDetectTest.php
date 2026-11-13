<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Picture;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;

/**
 * detect() routing matrix (sugar-charts-012/-043/-115, CH-23): multiplexer
 * state is first-class — under TMUX/ZELLIJ no escape emitter is trusted
 * unless CHARTS_PICTURE_PASSTHROUGH opts in — and Windows Terminal plus
 * kitty's KITTY_WINDOW_ID are now auto-detected arms.
 */
final class PictureDetectTest extends TestCase
{
    /**
     * @return array<string,array{0: array<string,string>, 1: ?Protocol}>
     */
    public static function directMatrix(): array
    {
        return [
            'iterm'        => [['TERM_PROGRAM' => 'iTerm.app'], Protocol::ITerm2],
            'wezterm'      => [['TERM_PROGRAM' => 'WezTerm'], Protocol::Sixel],
            'kitty-term'   => [['TERM' => 'xterm-kitty'], Protocol::Kitty],
            'kitty-window' => [['TERM' => 'xterm-256color', 'KITTY_WINDOW_ID' => '3'], Protocol::Kitty],
            'foot'         => [['TERM' => 'foot'], Protocol::Sixel],
            'mlterm'       => [['TERM' => 'mlterm'], Protocol::Sixel],
            'wt-session'   => [['WT_SESSION' => 'abc'], Protocol::Sixel],
            'wt-profile'   => [['WT_PROFILE_ID' => '{xyz}'], Protocol::Sixel],
            'bare-xterm'   => [['TERM' => 'xterm-256color'], null],
            'dumb'         => [['TERM' => 'dumb'], null],
            'empty-env'    => [[], null],
        ];
    }

    /** @dataProvider directMatrix */
    public function testUnmuxedRoutingTable(array $env, ?Protocol $expected): void
    {
        $this->assertSame($expected, Picture::detect($env));
    }

    /**
     * @return array<string,array{0: array<string,string>}>
     */
    public static function muxedEnvs(): array
    {
        return [
            'tmux+iterm (the inverted OSC 1337 miss)' => [['TERM_PROGRAM' => 'iTerm.app', 'TMUX' => '/tmp/tmux-1000/default,1234,0', 'TERM' => 'screen-256color']],
            'tmux+wezterm (sixel into an unforgiving pane)' => [['TERM_PROGRAM' => 'WezTerm', 'TMUX' => '/tmp/t,1,0']],
            'zellij+kitty (window id survives, TERM clobbered)' => [['TERM' => 'screen-256color', 'KITTY_WINDOW_ID' => '7', 'ZELLIJ' => '1']],
            'zellij-session-name arm' => [['TERM_PROGRAM' => 'iTerm.app', 'ZELLIJ_SESSION_NAME' => 'dev']],
            'tmux+wt' => [['WT_SESSION' => 'w', 'TMUX' => '/tmp/t,1,0']],
        ];
    }

    /** @dataProvider muxedEnvs */
    public function testMultiplexerGoesPlaceholderByDefault(array $env): void
    {
        $this->assertNull(
            Picture::detect($env),
            'an OSC/DCS emitter must never be chosen inside a pane by default',
        );
    }

    public function testPassthroughOptInRestoresOuterTerminalRouting(): void
    {
        $env = ['TERM_PROGRAM' => 'iTerm.app', 'TMUX' => '/tmp/t,1,0'];
        $this->assertNull(Picture::detect($env + ['CHARTS_PICTURE_PASSTHROUGH' => '0']));
        $this->assertSame(Protocol::ITerm2, Picture::detect($env + ['CHARTS_PICTURE_PASSTHROUGH' => '1']));
        // kitty-in-tmux inverted miss: the pane never saw TERM=xterm-kitty,
        // but the window id survives and passthrough catches it.
        $this->assertSame(
            Protocol::Kitty,
            Picture::detect(['TERM' => 'screen-256color', 'KITTY_WINDOW_ID' => '7', 'ZELLIJ' => '1', 'CHARTS_PICTURE_PASSTHROUGH' => '1']),
        );
    }

    public function testEmptyTmuxVariableIsNotAMultiplexer(): void
    {
        // tmux only exports TMUX with a socket spec; an empty value means
        // plain terminal, so the auto arms must stay live.
        $this->assertSame(
            Protocol::ITerm2,
            Picture::detect(['TERM_PROGRAM' => 'iTerm.app', 'TMUX' => '']),
        );
    }
}

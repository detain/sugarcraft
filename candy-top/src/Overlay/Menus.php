<?php

declare(strict_types=1);

namespace SugarCraft\Top\Overlay;

use SugarCraft\Core\Msg;
use SugarCraft\Top\Collect\ProcessControl;
use SugarCraft\Top\Lang;
use SugarCraft\Top\Msg\OpenOverlayMsg;
use SugarCraft\Top\View\Ink;

/**
 * Factories for btop's menus (src/btop_menu.cpp `menuFunc`) and the Cmds
 * that carry out their side effects — the one place a key, a panel or a
 * menu entry asks for a menu, so later phases swap an implementation here
 * without touching the callers.
 *
 * P-F2 plugs the options menu in by replacing {@see options()}: the main
 * menu's Options entry and the App's `o` / `F2` keys already route here.
 */
final class Menus
{
    private function __construct()
    {
    }

    public static function main(): Overlay
    {
        return MainMenu::new();
    }

    public static function help(): Overlay
    {
        return HelpMenu::new();
    }

    /**
     * The options menu. Phase P-F1 ships a notice in its place; P-F2
     * returns the real OptionsMenu (an Overlay writing options through
     * {@see OverlayResult::$set}, minSize 80x24).
     */
    public static function options(): Overlay
    {
        return MsgBox::ok(
            50,
            Lang::t('overlay.title.options'),
            static fn (Ink $ink): array => [
                MenuDraw::BOLD . $ink->fg('title') . Lang::t('overlay.options.pending') . $ink->fg('main_fg') . MenuDraw::UNBOLD,
                Lang::t('overlay.options.pending_detail'),
            ],
        );
    }

    /**
     * btop Menu::sizeError: a 45-wide `error` box — "Terminal size too
     * small to display menu or box!". Shown when a box toggle (or a
     * shown_boxes write) would not fit, and in place of any menu the
     * terminal is too small for.
     */
    public static function sizeError(): Overlay
    {
        return MsgBox::ok(
            45,
            Lang::t('overlay.title.error'),
            static fn (Ink $ink): array => [
                MenuDraw::BOLD . $ink->gradient('used', 100) . Lang::t('overlay.size.error') . $ink->fg('main_fg') . MenuDraw::UNBOLD,
                Lang::t('overlay.size.line1'),
                Lang::t('overlay.size.line2'),
            ],
            true,
        );
    }

    /**
     * btop Menu::signalSend (`t` = SIGTERM, `k` = SIGKILL): a 50-wide
     * Yes/No box titled with the signal's name; Yes sends it.
     */
    public static function signalSend(ProcessControl $control, int $pid, string $name, int $signal): Overlay
    {
        $sigName = Signals::name($signal);
        $title = $signal > 1 && $signal <= 32 && $signal !== 17 && $sigName !== null ? $sigName : Lang::t('overlay.title.signal');
        $name = MenuDraw::cut(MenuDraw::clean($name), 16);

        return MsgBox::confirm(
            50,
            $title,
            static fn (Ink $ink): array => [
                MenuDraw::BOLD . $ink->fg('main_fg') . Lang::t('overlay.signal.send') . MenuDraw::UNBOLD . $ink->fg('hi_fg') . $signal
                    . ($sigName !== null ? $ink->fg('main_fg') . ' (' . $sigName . ')' : ''),
                MenuDraw::BOLD . $ink->fg('main_fg') . Lang::t('overlay.signal.to_pid') . MenuDraw::UNBOLD . $ink->fg('hi_fg') . $pid
                    . $ink->fg('main_fg') . ' (' . $name . ')' . MenuDraw::RESET,
            ],
            self::sendSignal($control, $pid, $signal),
        );
    }

    /**
     * btop Menu::signalReturn: the 50-wide `error` box for a failed
     * kill() — EINVAL, EPERM, ESRCH or "Unknown error! (errno: n)".
     */
    public static function signalReturn(int $errno): Overlay
    {
        $text = match ($errno) {
            Signals::EINVAL => Lang::t('overlay.signal.einval'),
            Signals::EPERM => Lang::t('overlay.signal.eperm'),
            Signals::ESRCH => Lang::t('overlay.signal.esrch'),
            default => Lang::t('overlay.signal.unknown', ['errno' => $errno]),
        };

        return MsgBox::ok(
            50,
            Lang::t('overlay.title.error'),
            static fn (Ink $ink): array => [
                MenuDraw::BOLD . $ink->gradient('used', 100) . Lang::t('overlay.signal.failure') . $ink->fg('main_fg') . MenuDraw::UNBOLD,
                $text . MenuDraw::RESET,
            ],
        );
    }

    /**
     * The Cmd that sends `$signal` to `$pid` (btop `kill()` inside
     * signalChoose / signalSend): null on success, else an
     * {@see OpenOverlayMsg} with the failure box. A pid below 1 never
     * reaches kill() — btop reports it as ESRCH.
     */
    public static function sendSignal(ProcessControl $control, int $pid, int $signal): \Closure
    {
        return static function () use ($control, $pid, $signal): ?Msg {
            $errno = $pid < 1 ? Signals::ESRCH : $control->signal($pid, $signal);

            return $errno === 0 ? null : new OpenOverlayMsg(self::signalReturn($errno));
        };
    }

    /** The Cmd that renices `$pid` (btop Proc::set_priority); a failure opens the failure box. */
    public static function renice(ProcessControl $control, int $pid, int $nice): \Closure
    {
        return static function () use ($control, $pid, $nice): ?Msg {
            $errno = $control->renice($pid, $nice);

            return $errno === 0 ? null : new OpenOverlayMsg(self::signalReturn($errno));
        };
    }
}

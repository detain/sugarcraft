<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Code\Cli\Help;

/**
 * The eligibility probes and the once-per-version stamp behind the macOS
 * Option-as-Meta launch row ({@see \SugarCraft\Code\Cli\Bootstrap::macAltNotice()}).
 *
 * C-TUI-7.b (plan_comparison 6.15): the row used to fire on EVERY Darwin
 * launch whose terminal env did not name a kitty-speaking emulator. Two
 * audiences made that noise. First, a Mac running candy-code INSIDE tmux on
 * kitty/ghostty/wezterm: the emulator still speaks the kitty keyboard
 * protocol, but its identity variables survive the mux as real environment
 * (kitty exports `KITTY_WINDOW_ID`, ghostty `GHOSTTY_RESOURCES_DIR`, wezterm
 * `WEZTERM_PANE`) while `TERM` is rewritten to `screen`-family — so the old
 * probe answered "not kitty-capable" from inside the very terminals that
 * negotiated, and the hint told the user to go flip a setting they never
 * needed to touch. The probe therefore consults those transparent carriers in
 * addition to TERM/TERM_PROGRAM. Second, a Mac on Terminal.app/iTerm2 that
 * HAS read the row: repeating the same sentence every launch teaches the eye
 * to skip it, so the row is recorded once per candy-code version — a stamp
 * file under the owned home ({@see HomeDirectory::owned()}, the one home
 * resolution this package allows) carries the version it last announced for,
 * and a new version re-arms the notice because an upgrade can change the
 * keyboard story.
 *
 * The stamp is written when the row is SHOWN, not when it is decided: a
 * launch whose sink is unarmed (headless `-p`) records nothing and must not
 * consume the version, or a TUI launched afterwards would be silently
 * skipped. That ordering lives in the caller; this class only answers
 * "would this launch show it, and remember that it did".
 *
 * Fail-soft in both directions of honesty: no owned home, or an unreadable/
 * unwritable stamp, means the row may show again (noise over silence) — the
 * never-show case requires a stamp that demonstrably matches.
 */
final class MacAltNotice
{
    /** File name of the once-per-version stamp inside `~/.candy-code/cache/`. */
    public const STAMP_FILE = 'mac-alt-notice';

    /**
     * Names that identify a terminal this build negotiates the kitty keyboard
     * protocol with, searched (case-insensitively) inside the terminal env
     * values.
     *
     * @var list<string>
     */
    public const KITTY_FAMILY_TERMS = ['kitty', 'ghostty', 'wezterm'];

    /**
     * Environment variables the three kitty-speaking emulators export that
     * survive a tmux (or screen) session — inside the mux `TERM` reads
     * `screen`-family, so these carriers are the terminal's identity.
     *
     * @var list<string>
     */
    public const TMUX_TRANSPARENT_ENV = ['KITTY_WINDOW_ID', 'GHOSTTY_RESOURCES_DIR', 'WEZTERM_PANE'];

    /**
     * Whether the Option-as-Meta row belongs to a launch of this shape at all
     * (version stamp aside): a Mac whose terminal did not present itself as
     * kitty-capable. PURE so every quadrant stays pinned on non-Mac hosts.
     */
    public static function belongs(string $osFamily, bool $kittyCapable): bool
    {
        return $osFamily === 'Darwin' && !$kittyCapable;
    }

    /**
     * Whether the ambient terminal environment names a kitty-speaking
     * emulator — either spelled directly (`TERM`/`TERM_PROGRAM`, set by the
     * emulators themselves) or carried through a multiplexer via
     * {@see TMUX_TRANSPARENT_ENV}, which is what makes the probe tmux-aware.
     *
     * The `$env` seam exists for the pins: production always calls it with no
     * argument and reads the process environment.
     *
     * @param array<string, string>|null $overridden ambient env, null to read getenv()
     */
    public static function terminalSpeaksKittyKeyboard(?array $env = null): bool
    {
        $term = $env !== null
            ? (string) ($env['TERM'] ?? '')
            : (string) (\getenv('TERM') ?: '');
        $program = $env !== null
            ? (string) ($env['TERM_PROGRAM'] ?? '')
            : (string) (\getenv('TERM_PROGRAM') ?: '');

        $spelled = \strtolower($term . ' ' . $program);
        foreach (self::KITTY_FAMILY_TERMS as $terminal) {
            if (\str_contains($spelled, $terminal)) {
                return true;
            }
        }

        foreach (self::TMUX_TRANSPARENT_ENV as $name) {
            $carried = $env !== null ? (string) ($env[$name] ?? '') : (string) (\getenv($name) ?: '');
            if ($carried !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the row should be recorded on THIS launch, consuming the
     * version: shows once per candy-code version, silently re-arming whenever
     * the stamped version differs — including when the stamp cannot be read
     * or written (fail-soft toward showing; silence requires a demonstrable
     * match).
     *
     * Eligibility is judged by the caller BEFORE this is consulted, so a
     * Linux launch never touches the stamp file, and the call is placed after
     * the sink is armed, so a dropped headless record does not burn the
     * version.
     *
     * @param string|null $version version to stamp, null to ask {@see Help::versionString()}
     * @param string|null $stampPath stamp file to use, null for the owned-home default
     */
    public static function showsOnThisLaunch(?string $version = null, ?string $stampPath = null): bool
    {
        $path = $stampPath ?? self::defaultStampPath();
        if ($path === null) {
            // No owned home to stamp in: showing again is the honest
            // fallback — there is nowhere trusted to have remembered it.
            return true;
        }

        $current = $version ?? Help::versionString();
        if (self::readStamp($path) === $current) {
            return false;
        }

        self::writeStamp($path, $current);

        return true;
    }

    /**
     * The production stamp location: `~/.candy-code/cache/mac-alt-notice`
     * under the single owned-home resolution, or null when no home can be
     * established as this user's.
     */
    private static function defaultStampPath(): ?string
    {
        $home = HomeDirectory::owned();
        if ($home === null) {
            return null;
        }

        return \rtrim($home, '/') . '/.candy-code/cache/' . self::STAMP_FILE;
    }

    /** The stamped version, or null when there is no readable stamp. */
    private static function readStamp(string $path): ?string
    {
        if (!\is_file($path)) {
            return null;
        }

        $body = @\file_get_contents($path);
        if (!\is_string($body)) {
            return null;
        }

        $body = \trim($body);

        return $body === '' ? null : $body;
    }

    /** Best-effort stamp; an unwritable cache only means showing again. */
    private static function writeStamp(string $path, string $version): void
    {
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0700, true) && !\is_dir($dir)) {
            return;
        }

        @\file_put_contents($path, $version);
    }
}

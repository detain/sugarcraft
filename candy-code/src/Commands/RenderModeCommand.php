<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Code\Config\Settings\Definitions\MediaSettings;
use SugarCraft\Video\Render\Mode as VideoMode;

/**
 * argv-style parser behind the `/rendermode` console command (crush-media W5.7).
 *
 * The command exists because the session carries TWO render ladders and only
 * one setting: `ui.imageRenderMode` governs IMAGE painting through candy-
 * mosaic, while the video player rides sugar-video's OWN ladder (see
 * {@see \SugarCraft\Code\App\App::videoPlayerMode()} — a word that names a
 * video mode seeds the player's start, everything else fails soft to the
 * player's own auto-ladder). `/rendermode` makes both ladders visible in one place and
 * switches the image word without a restart.
 *
 * Like {@see PlayCommand} this class is pure: no settings I/O, no probing,
 * no Chat state. Validation is membership in {@see MediaSettings::RENDER_
 * MODES} — the same list the settings Enum validator enforces and
 * MediaSettingsTest re-derives against mosaic's `fromModeString` oracle — so
 * parsing a word never touches the terminal. The video analogue is reported
 * through the pure `VideoMode::tryFrom` half of the pick law only; the
 * `autoMode()` half PROVES nothing about a typed word and probes the TTY, so
 * it stays out of this file entirely.
 */
final class RenderModeCommand
{
    /** Short one-line synopsis used for the bare-command usage answer. */
    public const USAGE = '/rendermode [mode]';

    /** The settings key this command writes through the validated door. */
    public const SETTINGS_KEY = 'ui.imageRenderMode';

    private function __construct()
    {
    }

    /**
     * Parse quote-split argv tokens (everything after `/rendermode`).
     *
     * @param list<string> $tokens
     * @return array{word: ?string, help: bool, error: ?string}
     *   `word` is the normalized mode to set (null = show current state);
     *   `help` short-circuits to the flag block; `error` carries the
     *   usage-level refusal sentence and empties everything else.
     */
    public static function parse(array $tokens): array
    {
        $word = null;
        $help = false;

        foreach ($tokens as $token) {
            if ($token === '--help' || $token === '-h') {
                $help = true;
                continue;
            }

            if (str_starts_with($token, '-')) {
                return self::refusal(sprintf('unknown flag "%s". %s', $token, self::USAGE));
            }

            if ($word !== null) {
                return self::refusal('rendermode takes exactly one mode. ' . self::USAGE);
            }

            $checked = self::check($token);
            if ($checked !== null) {
                return self::refusal($checked);
            }

            $word = strtolower(trim($token));
        }

        if ($help) {
            return ['word' => null, 'help' => true, 'error' => null];
        }

        return ['word' => $word, 'help' => false, 'error' => null];
    }

    /** The usage sentence for the bare command. */
    public static function usage(): string
    {
        return self::USAGE . ' — show or switch the media render mode (images now, player start-mode next /play)';
    }

    /** The `--help` answer, including both ladders in plain words. */
    public static function help(): string
    {
        return implode("\n", [
            self::USAGE,
            '',
            '  no argument       show the current word and both render ladders',
            '  <mode>            set ui.imageRenderMode and re-derive from it now',
            '  --help            this block',
            '',
            '  allowed: ' . implode(', ', MediaSettings::RENDER_MODES),
            '  images:  auto ladder kitty > iterm2 > sixel > chafa > halfblock',
            '  video:   the player keeps its own ladder (m cycles it live);',
            '           a video-shaped word seeds its start, otherwise auto',
            '           goes sixel > kitty > iterm2 > truecolor > ansi256 > ascii',
        ]);
    }

    /**
     * The bare-command report: current word, its video analogue, the ladder
     * explanation the plan asks to live in one place. Pure string over the
     * already-normalized static — no probe, no settings read.
     */
    public static function report(string $current): string
    {
        $analogue = VideoMode::tryFrom($current);

        return implode("\n", [
            'render mode: ' . $current . '  (new image labels and the next /play follow this word; transcript painting keeps the launch-time renderer until the next launch)',
            'video analogue: ' . ($analogue?->value ?? 'none — player uses its own auto-ladder') . '  (cycle live with m inside the player)',
            '',
            '  allowed: ' . implode(', ', MediaSettings::RENDER_MODES),
            '  set one with /rendermode <mode>; CANDY_CODE_MEDIA_RENDER_MODE reasserts at next launch when set.',
        ]);
    }

    /**
     * The refusal sentence for one bad mode token, or null when it names an
     * allowed word. Membership test against the settings list keeps parsing
     * probe-free — `Mosaic::fromModeString('auto')` would answer with a live
     * capability probe, which has no business inside an argv parser.
     */
    private static function check(string $token): ?string
    {
        $word = strtolower(trim($token));

        if ($word === '') {
            return 'empty mode. ' . self::USAGE;
        }

        if (!\in_array($word, MediaSettings::RENDER_MODES, true)) {
            return sprintf(
                'unknown render mode "%s" — allowed: %s',
                $word,
                implode(', ', MediaSettings::RENDER_MODES),
            );
        }

        return null;
    }

    /**
     * @return array{word: null, help: bool, error: string}
     */
    private static function refusal(string $sentence): array
    {
        return ['word' => null, 'help' => false, 'error' => $sentence];
    }
}

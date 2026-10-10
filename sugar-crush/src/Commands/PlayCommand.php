<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

/**
 * argv-style parser behind the `/play` console command (crush-media W5.4).
 *
 * `/play` hands a finished video artifact — or any local clip — to the
 * full-screen player. Like {@see SendToCommand} this class is pure: no
 * transport, no settings, no Chat state. What it decides is the single
 * target token and the usage refusals around it.
 *
 * Path law (plan §W5.4): a token containing a slash must be a real,
 * readable file the OPERATOR typed — user-typed absolute paths are the
 * consent, and playback is a read-only media process, so the jail guards
 * guesses, not intentions. A RELATIVE token carrying `..` segments is
 * refused outright: nothing in this session ever names a video by walking
 * upward, so such a spelling is a guess at somebody else's file, not the
 * operator's words. A bare name with no slash is an artifact lookup in
 * the session's media store, decided by the host command. Remote
 * spellings (http(s)://, data:) are refused at the door exactly like the
 * import law — the player reads local disk only.
 */
final class PlayCommand
{
    /** Short one-line synopsis used for the bare-command usage answer. */
    public const USAGE = '/play <file|artifact-name>';

    /** Spellings that are never a local target, whatever their suffix. */
    public const REMOTE_PREFIXES = ['http://', 'https://', 'data:', 'ftp://'];

    private function __construct()
    {
    }

    /**
     * Parse quote-split argv tokens (everything after `/play`).
     *
     * @param list<string> $tokens
     * @return array{path: ?string, help: bool, error: ?string}
     *   `path` is the single target token; `help` short-circuits to the
     *   flag block; `error` carries the usage-level refusal sentence and
     *   empties everything else.
     */
    public static function parse(array $tokens): array
    {
        $path = null;
        $help = false;

        foreach ($tokens as $token) {
            if ($token === '--help' || $token === '-h') {
                $help = true;
                continue;
            }

            if (str_starts_with($token, '--')) {
                return self::refusal(sprintf('unknown flag "%s". %s', $token, self::USAGE));
            }

            if ($path !== null) {
                return self::refusal('play takes exactly one target. ' . self::USAGE);
            }

            $checked = self::check($token);
            if ($checked !== null) {
                return self::refusal($checked);
            }

            $path = $token;
        }

        if ($help) {
            return ['path' => null, 'help' => true, 'error' => null];
        }

        return ['path' => $path, 'help' => false, 'error' => null];
    }

    /** The usage sentence for the bare command. */
    public static function usage(): string
    {
        return self::USAGE . ' — plays a saved video artifact by name, or a local clip by path';
    }

    /** The `--help` answer. */
    public static function help(): string
    {
        return implode("\n", [
            self::USAGE,
            '',
            '  <artifact-name>   a video saved this session (mp4, gif, webm, mov, mkv, avi)',
            '  <path>            any readable local clip; absolute paths are honoured as typed',
            '  space play/pause · left/right seek · [ ] speed · m render mode · q or Esc close',
        ]);
    }

    /**
     * The refusal sentence for one bad target token, or null when it may
     * be looked up. Guard-clause order is the law's reading order.
     */
    private static function check(string $token): ?string
    {
        if ($token === '') {
            return 'empty target. ' . self::USAGE;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
            return 'the target contains control characters.';
        }

        foreach (self::REMOTE_PREFIXES as $prefix) {
            if (stripos($token, $prefix) === 0) {
                return 'remote targets are not playable — /play reads local files only, got "' . $token . '".';
            }
        }

        if (!str_starts_with($token, '/') && self::hasDotSegment($token)) {
            return 'relative ".." paths are refused — name the artifact or type the full path.';
        }

        return null;
    }

    /** Whether any `/`-separated segment of a relative token is exactly `..`. */
    private static function hasDotSegment(string $token): bool
    {
        foreach (explode('/', $token) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{path: null, help: bool, error: string}
     */
    private static function refusal(string $sentence): array
    {
        return ['path' => null, 'help' => false, 'error' => $sentence];
    }
}

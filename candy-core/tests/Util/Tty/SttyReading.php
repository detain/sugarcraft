<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

/**
 * Reads a terminal's flags with `stty -a` and answers whether a named flag is
 * SET or CLEARED.
 *
 * ## Why the flags are matched as WHOLE WORDS and not as substrings
 *
 * `stty -a` prints one token per flag and negates a flag with a leading `-`.
 * It also prints a family of ECHO-prefixed flags -- `echoe`, `echok`,
 * `echonl`, `echoprt`, `echoctl`, `echoke` -- each of which can itself be
 * negated. So the characters of a negated ECHO flag occur as a SUBSTRING of a
 * negated ECHONL flag, on a terminal where ECHO is switched ON.
 *
 * MEASURED, PHP 8.3.6, GNU coreutils `stty`, against a real pty slave:
 *
 *   `sane`         a substring test for the negated ECHO spelling is TRUE,
 *                  while ECHO is on -- it is matching inside the negated
 *                  ECHONL and ECHOPRT tokens.
 *   `-icanon echo` a substring test calls this raw. ECHO is on; it is not.
 *   `raw -echo`    genuinely raw, and both tests agree.
 *
 * A substring test is therefore TRUE in BOTH polarities and asserts nothing.
 * That is not a theoretical hazard: it shipped. MEASURED (mutations MA_ISRAW
 * and MA2_PROBE), whole candy-core suite, 827 tests / 7512 assertions / rc 0
 * -- deleting the ECHO conjunct outright from the substring form SURVIVED in
 * both of the two files that carried a copy of it. A raw mode that cleared
 * ICANON and left ECHO on would have passed them.
 *
 * ## Why this is a class and not a helper on each caller
 *
 * There were two copies of that clause, in a test and in the child probe it
 * drives, and both were wrong in the same way -- which is what a copy is for.
 * One implementation, pinned once, is the fix for the duplication as well as
 * for the matching. {@see cookedFixture()} lets any caller push a reading
 * whose answer is known through the same matcher, so a matcher mutated to
 * match NOTHING -- which would report "not raw" forever, the same silence one
 * level down -- is caught rather than believed.
 */
final class SttyReading
{
    private function __construct()
    {
    }

    /**
     * The `stty -a` reading for the device at $path, or `''` if none could be
     * taken.
     *
     * GNU coreutils takes the device flag uppercase and BSD/macOS takes it
     * lowercase. The wrong one prints nothing at all, which a caller must not
     * be able to mistake for a reading of a cooked terminal -- hence `''`
     * rather than a default reading.
     */
    public static function of(string $path): string
    {
        $flag = \PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';

        return trim((string) shell_exec('stty ' . $flag . ' ' . escapeshellarg($path) . ' -a 2>/dev/null'));
    }

    /** True when $flag appears NEGATED in $reading, matched as a whole word. */
    public static function isOff(string $reading, string $flag): bool
    {
        return self::hasToken($reading, '-' . $flag);
    }

    /** True when $flag appears SET in $reading, matched as a whole word. */
    public static function isOn(string $reading, string $flag): bool
    {
        return self::hasToken($reading, $flag);
    }

    /**
     * True when $reading shows a terminal in raw mode: ICANON cleared AND
     * ECHO cleared.
     *
     * Both halves are required. The ICANON half alone is what the substring
     * form effectively asserted once its ECHO half was neutralised by the
     * lookalikes described above.
     */
    public static function isRaw(string $reading): bool
    {
        return self::isOff($reading, 'icanon') && self::isOff($reading, 'echo');
    }

    /**
     * A synthetic COOKED reading that carries the lookalike trap.
     *
     * Held here rather than written into one test so that the control travels
     * with the matcher. Its shape is a transcript of a real GNU coreutils
     * `stty -a` reading of a `sane` pty slave taken on this box, PHP 8.3.6:
     * ECHO is ON, and the negated ECHONL and ECHOPRT tokens are present, so
     * the naive substring test reports this terminal as raw.
     *
     * A caller asserting an absence against this fixture is asserting
     * something the fixture actually contains -- which is the difference
     * between a control and a tautology.
     */
    public static function cookedFixture(): string
    {
        return 'speed 38400 baud; rows 24; columns 80; line = 0;' . "\n"
            . 'intr = ^C; quit = ^\; erase = ^?; kill = ^U; eof = ^D;' . "\n"
            . '-parenb -parodd cs8 hupcl -cstopb cread -clocal -crtscts' . "\n"
            . '-ignbrk brkint -ignpar -parmrk -inpck -istrip -inlcr -igncr icrnl ixon' . "\n"
            . 'opost -olcuc -ocrnl onlcr -onocr -onlret -ofill -ofdel nl0 cr0' . "\n"
            . 'isig icanon iexten echo echoe echok -echonl -echoprt echoctl echoke -flusho';
    }

    /**
     * Whole-word containment.
     *
     * The separator class is `[\s;]` because that is what a reading is made
     * of: `stty -a` puts a semicolon after each entry of its first two lines
     * and a space between every flag thereafter. A leading `-` is part of the
     * token, never a separator, which is the whole point.
     */
    private static function hasToken(string $reading, string $token): bool
    {
        return preg_match(
            '/(?:^|[\s;])' . preg_quote($token, '/') . '(?:[\s;]|$)/',
            $reading,
        ) === 1;
    }
}

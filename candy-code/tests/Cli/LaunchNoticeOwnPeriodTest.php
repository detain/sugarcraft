<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * ISS-081: every launch notice that reaches stderr is wrapped by
 * {@see Bootstrap::STDERR_LINE_FORMAT} — `"candy-code: %s.\n"` — which OWNS
 * the sentence-final full stop. The comments around the constant's siblings
 * (`SESSION_RETENTION_DETAIL_FORMAT`, `PROJECT_TIER_REFUSAL_FORMAT`, the
 * MCP start-failure line) each state the corollary: a message routed through
 * the format must not carry its own stop, or the operator reads `..` — and
 * every `-p` and `doctor` run did exactly that for the bypass banner, whose
 * lang string ended in `config.json.` (reproduced: `~/.candy-code/config.json..`).
 *
 * The source strings live in the catalogue, so a call-site scan could never
 * see them; these pins derive the families from `lang/en.php` itself. The
 * families are a deliberate OVER-APPROXIMATION of "routed through
 * STDERR_LINE_FORMAT": `appLaunch.notice.*` and `cli.warn.*` are the
 * launch-warning namespaces, every current member of both reaches stderr
 * through the period-adding format, and a future member that wanted its own
 * stop would want the channel, not an exception to this pin. The rule reads
 * one way on purpose — silence here is cheap, a dotted sentence on a bare
 * terminal line is not.
 */
final class LaunchNoticeOwnPeriodTest extends TestCase
{
    /** Key prefixes whose values are routed through the period-adding format. */
    private const ROUTED_FAMILIES = ['appLaunch.notice.', 'cli.warn.'];

    /**
     * The format itself, byte for byte: prefix, the one sentence, and
     * exactly one full stop before the newline.
     */
    public function testTheStderrFormatAppendsExactlyOneFullStop(): void
    {
        $line = \sprintf(Bootstrap::STDERR_LINE_FORMAT, 'A notice without its own stop');

        self::assertSame("candy-code: A notice without its own stop.\n", $line);
    }

    /**
     * No routed string carries its own full stop. Derived from the source
     * of truth (`lang/en.php`) rather than from `Lang::t()`, so a locale
     * lookup could never vacuously green this: English is the catalogue the
     * routing rule is written against, and every other locale is parity
     * pinned to its key set by `tests/LangParityTest.php`.
     */
    public function testNoRoutedLaunchNoticeCarriesItsOwnFullStop(): void
    {
        $catalogue = require \dirname(__DIR__, 2) . '/lang/en.php';
        self::assertIsArray($catalogue);

        $routed = [];
        foreach ($catalogue as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }

            foreach (self::ROUTED_FAMILIES as $prefix) {
                if (\str_starts_with((string) $key, $prefix)) {
                    $routed[$key] = $value;
                }
            }
        }

        // Vacuity doors: the bypass banner whose fix minted this pin must
        // still be here, and the families must not have silently emptied.
        self::assertArrayHasKey('appLaunch.notice.bypass_mode', $routed);
        self::assertArrayHasKey('appLaunch.notice.policy_changed', $routed);
        self::assertNotEmpty($routed);

        foreach ($routed as $key => $value) {
            self::assertFalse(
                \str_ends_with(\rtrim($value), '.'),
                "lang key '{$key}' carries its own full stop while STDERR_LINE_FORMAT adds one",
            );
        }
    }

    /**
     * The shipped bytes of the two banners whose strings this fix clipped:
     * through the format, each produces exactly one trailing period — the
     * shape the ISS-081 repro (`config.json..`) refused.
     */
    public function testTheBypassBannerReachesStderrWithOnePeriod(): void
    {
        $catalogue = require \dirname(__DIR__, 2) . '/lang/en.php';
        $bypass = (string) $catalogue['appLaunch.notice.bypass_mode'];

        $line = \sprintf(Bootstrap::STDERR_LINE_FORMAT, $bypass);

        self::assertStringEndsWith("config.json.\n", $line);
        self::assertStringNotContainsString('..', $line);
    }
}

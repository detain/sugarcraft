<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\SshLauncher\Lang;

/**
 * Unicode bidi isolate pins for the Arabic locale (candy-ssh-launcher-102 /
 * sshl-35): LTR payloads (paths, binaries, field tokens, `-o`) interpolated
 * into RTL sentences must sit inside LRI (U+2066) … PDI (U+2069) runs, or an
 * RTL renderer can drag the trailing run to the wrong edge — garbling the
 * error exactly where the user needs to read it.
 *
 * The isolates are wrapping, never key change: placeholder multisets stay
 * identical (LocaleParityTest guards), and Sanitize::untrusted() passes
 * isolate bytes untouched while the display policy marks them — so the wrap
 * survives every CLI emit path (docs/TRUST-BOUNDARY.md error wrapping).
 */
final class ArabicBidiIsolatesTest extends TestCase
{
    /** LTR payload placeholders that must render inside an isolate run. */
    private const WRAPPED_PLACEHOLDERS = [
        'path', 'bin', 'arg', 'found', 'value', 'key', 'field',
        'name', 'host', 'message', 'type', 'port',
    ];

    private const LRI = "\u{2066}";
    private const PDI = "\u{2069}";

    /** @return array<string, string> */
    private static function ar(): array
    {
        /** @var array<string, string> $rows */
        $rows = require __DIR__ . '/../lang/ar.php';
        return $rows;
    }

    public function testIsolatesAreBalancedInEveryRow(): void
    {
        foreach (self::ar() as $key => $value) {
            $this->assertSame(
                substr_count($value, self::LRI),
                substr_count($value, self::PDI),
                "unbalanced bidi isolates in '{$key}'"
            );
        }
    }

    public function testEveryLtrPlaceholderSitsInsideAnIsolateRun(): void
    {
        $pattern = '/\{(' . implode('|', self::WRAPPED_PLACEHOLDERS) . ')\}/';
        foreach (self::ar() as $key => $value) {
            if (substr_count($value, self::LRI) === 0 && !preg_match($pattern, $value)) {
                continue; // RTL-only row with no wrapped payload class
            }
            preg_match_all('/' . preg_quote(self::LRI, '/') . '([^' . self::LRI . self::PDI . ']*)' . preg_quote(self::PDI, '/') . '/u', $value, $spans);
            $inside = preg_match_all($pattern, implode('', $spans[1]));
            $total  = preg_match_all($pattern, $value);
            $this->assertSame(
                $total,
                (int) $inside,
                "at least one LTR placeholder in '{$key}' renders outside a bidi isolate"
            );
        }
    }

    public function testNoIsolateSplitsAPlaceholderToken(): void
    {
        // Stripping the isolates must hand back byte-adjacent `{token}`
        // placeholders — an isolate may wrap a token, never cut into it.
        $split = '/\{[^}]*' . preg_quote(self::LRI, '/') . '[^}]*\}|\{[^}]*' . preg_quote(self::PDI, '/') . '[^}]*\}/u';
        foreach (self::ar() as $key => $value) {
            $this->assertSame(0, preg_match($split, $value), "an isolate splits a placeholder in '{$key}'");
            $plain = str_replace([self::LRI, self::PDI], '', $value);
            $this->assertNotSame('', $plain, "'{$key}' collapsed to empty once isolates are removed");
        }
    }

    public function testComposedMessagesCarryTheIsolateAroundTheValue(): void
    {
        T::setLocale('ar');
        try {
            $msg = Lang::t('config.unreadable', ['path' => '/srv/x.yml']);
            $this->assertStringContainsString(self::LRI . '/srv/x.yml' . self::PDI, $msg);
            $msg2 = Lang::t('cli.ssh_not_executable', ['bin' => '/opt/odd ssh']);
            $this->assertStringContainsString(self::LRI . '/opt/odd ssh' . self::PDI, $msg2);
            $msg3 = Lang::t('endpoint.option_injection', ['field' => 'user', 'value' => '-o']);
            $this->assertStringContainsString(self::LRI . 'user' . self::PDI, $msg3);
        } finally {
            T::setLocale('en');
        }
    }
}

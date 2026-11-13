<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Lang;

/**
 * S-L12: the single-counter `cli.doctor.*` rows carry a whole sentence per
 * plural class (`_one` / `_other`, the `cli.noticerow.*` precedent) instead of
 * the English `(s)` splice that every locale used to inherit. Rows with two or
 * more counters in one sentence (`channels_store_ok`, `otlp_degraded`) keep
 * the `(s)` idiom — see candy-code/CALIBER_LEARNINGS.md.
 */
final class LangDoctorPluralTest extends TestCase
{
    private const KEYS = [
        'bundles_escape', 'bundles_first_sight', 'bundles_loose', 'bundles_ok',
        'bundles_stale', 'inbound_corralled', 'mcp_servers', 'sessions',
    ];

    /** Locales whose languages inflect the noun for one vs. many. */
    private const INFLECTING = ['en', 'de', 'es', 'fr', 'it', 'pt', 'pt-br'];

    public function testEveryLocaleCarriesBothClassesWithTheSamePlaceholders(): void
    {
        $files = \glob(\dirname(__DIR__) . '/lang/*.php') ?: [];
        $this->assertCount(12, $files);
        foreach ($files as $file) {
            $catalogue = require $file;
            foreach (self::KEYS as $key) {
                $this->assertArrayNotHasKey('cli.doctor.' . $key, $catalogue, "$file: the base key is gone, only _one/_other remain");
                foreach (['_one', '_other'] as $suffix) {
                    $this->assertArrayHasKey('cli.doctor.' . $key . $suffix, $catalogue, $file);
                    $this->assertStringNotContainsString('(s)', $catalogue['cli.doctor.' . $key . $suffix], "$file $key$suffix");
                }
                $this->assertSame(
                    $this->placeholders($catalogue['cli.doctor.' . $key . '_one']),
                    $this->placeholders($catalogue['cli.doctor.' . $key . '_other']),
                    "$file $key: both classes name the same placeholders",
                );
            }
        }
    }

    public function testInflectingLocalesSayOneAndManyDifferently(): void
    {
        foreach (self::INFLECTING as $locale) {
            $catalogue = require \dirname(__DIR__) . '/lang/' . $locale . '.php';
            foreach (self::KEYS as $key) {
                if ($key === 'mcp_servers' && $locale === 'de') {
                    continue; // German 'Server' is the same word in both numbers
                }
                $this->assertNotSame($catalogue['cli.doctor.' . $key . '_one'], $catalogue['cli.doctor.' . $key . '_other'], "$locale $key");
            }
        }
    }

    public function testEnglishReadsAsPlainGrammar(): void
    {
        $line = static fn (string $key, int $n): string => Lang::inLocale('en', static fn (): string => Lang::t($key, ['count' => $n, 'path' => 'P']));

        $this->assertSame('1 session stored', $line('cli.doctor.sessions_one', 1));
        $this->assertSame('2 sessions stored', $line('cli.doctor.sessions_other', 2));
        $this->assertSame('0 servers declared in P', $line('cli.doctor.mcp_servers_other', 0));
        $this->assertSame('1 server declared in P', $line('cli.doctor.mcp_servers_one', 1));
    }

    /** @return list<string> */
    private function placeholders(string $text): array
    {
        \preg_match_all('/\{(\w+)\}/', $text, $m);
        $names = \array_values(\array_unique($m[1]));
        \sort($names);

        return $names;
    }
}

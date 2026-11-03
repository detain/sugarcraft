<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Roster guard for the i18n locale files (audit LOW-16).
 *
 * Every non-en locale must carry EXACTLY the key set of lang/en.php —
 * missing keys silently fall back to English (harmless at runtime but
 * the roster drifts and translators lose track), and extra keys name
 * lookups that no longer exist in en. Placeholders ({reason}, {timeout},
 * …) must match en verbatim per key or interpolation breaks.
 *
 * Mirrors the repo's documentation-drift-guard philosophy: the roster
 * is pinned by the test, not by convention.
 */
final class LangRosterTest extends TestCase
{
    /** @return array<string,array{0:string}> */
    public static function localeProvider(): array
    {
        $cases = [];
        foreach (glob(\dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            if ($code === 'en') {
                continue;
            }
            $cases[$code] = [$file];
        }
        if (\count($cases) < 15) {
            throw new \LogicException('all fifteen non-en locales must exist, found ' . \count($cases));
        }

        return $cases;
    }

    /**
     * Every locale file, English included — {@see localeProvider}
     * deliberately skips en (it is the source of truth for the roster
     * diff), but a wrong SENTENCE is wrong in the source too.
     *
     * @return array<string,array{0:string}>
     */
    public static function everyLocaleProvider(): array
    {
        $cases = [];
        foreach (glob(\dirname(__DIR__) . '/lang/*.php') ?: [] as $file) {
            $cases[basename($file, '.php')] = [$file];
        }
        if (\count($cases) !== 16) {
            throw new \LogicException('all sixteen locales must exist, found ' . \count($cases));
        }

        return $cases;
    }

    /**
     * @dataProvider localeProvider
     */
    public function testLocaleCarriesExactlyTheEnglishKeySet(string $file): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $loc = include $file;
        $this->assertIsArray($en);
        $this->assertIsArray($loc);

        $missing = array_diff(array_keys($en), array_keys($loc));
        $extra = array_diff(array_keys($loc), array_keys($en));

        $this->assertSame([], $missing, basename($file) . ' is missing keys: ' . implode(', ', $missing));
        $this->assertSame([], $extra, basename($file) . ' carries keys en.php no longer has: ' . implode(', ', $extra));
    }

    /**
     * @dataProvider localeProvider
     */
    public function testPlaceholdersMatchEnglishVerbatim(string $file): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $loc = include $file;

        foreach ($en as $key => $englishValue) {
            if (!\is_string($englishValue) || !array_key_exists($key, $loc) || !\is_string($loc[$key])) {
                continue;
            }
            preg_match_all('/\{(\w+)\}/', $englishValue, $expected);
            preg_match_all('/\{(\w+)\}/', $loc[$key], $actual);
            $this->assertSame(
                $expected[1],
                $actual[1],
                \sprintf('%s: placeholder set for "%s" must match en', basename($file), $key),
            );
        }
    }

    public function testEveryEnglishKeyHasANonEmptyValue(): void
    {
        $en = include \dirname(__DIR__) . '/lang/en.php';
        foreach ($en as $key => $value) {
            $this->assertIsString($value, "en.php value for {$key} must be a string");
            $this->assertNotSame('', trim($value), "en.php value for {$key} must not be blank");
        }
    }

    public function testNoEnglishKeyIsDeadInSource(): void
    {
        // LOW-16b discipline: keys without a Lang::t producer drift
        // unnoticed. Scan src/ for every literal key referenced via
        // Lang::t('…') and demand the en roster contains nothing else.
        $en = include \dirname(__DIR__) . '/lang/en.php';
        $used = [];
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($rii as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (preg_match_all("/Lang::t\(\s*'([^']+)'/", $src, $m)) {
                foreach ($m[1] as $key) {
                    $used[$key] = true;
                }
            }
        }
        $dead = array_diff(array_keys($en), array_keys($used));
        $this->assertSame([], $dead, 'en.php keys with no Lang::t producer in src/: ' . implode(', ', $dead));
    }

    /**
     * The remedy offered by the BubbleTea transport error must name the
     * transport that actually mounts a Program (audit K11).
     *
     * All sixteen locales shipped the same sentence, and that sentence
     * told you to 'pass Server::withTransport(new InProcessTransport())'
     * — the transport you were already standing on, and the very one the
     * exception exists to refuse. Following the advice re-throws the
     * same error, so the string was not merely unhelpful: it was a
     * dead end printed as a fix. The class docblock in
     * src/Middleware/BubbleTea.php had the remedy right all along, which
     * is the half-moved-truth shape this pin guards against: the
     * docblock was cured and the shipped message was not.
     *
     * @dataProvider everyLocaleProvider
     */
    public function testTheBubbleTeaRemedyNamesTheTransportThatWorks(string $file): void
    {
        $loc = include $file;
        $this->assertIsArray($loc);
        $message = (string) ($loc['bubbletea.requires_host_sshd'] ?? '');

        $this->assertStringContainsString(
            'withTransport(new HostSshdTransport())',
            $message,
            basename($file) . ' must send the reader to HostSshdTransport',
        );
        $this->assertStringNotContainsString(
            'withTransport(new InProcessTransport())',
            $message,
            basename($file) . ' must not prescribe the transport the error refuses',
        );
    }

    public function testTheBubbleTeaDocblockAndTheShippedMessageAgree(): void
    {
        $doc = (string) file_get_contents(\dirname(__DIR__) . '/src/Middleware/BubbleTea.php');
        $en = include \dirname(__DIR__) . '/lang/en.php';

        $this->assertStringContainsString('withTransport(new HostSshdTransport())', $doc);
        $this->assertStringNotContainsString('withTransport(new InProcessTransport())', $doc);
        $this->assertStringContainsString(
            'Spawn middleware',
            (string) $en['bubbletea.requires_host_sshd'],
            'the second migration path must survive in the message too',
        );
    }
}

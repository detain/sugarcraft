<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The manifest and the README must tell the same platform story
 * (findings sugar-core-028 and -077 DS-04, sc-37).
 *
 * Before this row, `ext-pcntl` was the missing word in both places: the
 * capability degrades on four surfaces when the extension is absent, and
 * neither composer `suggest` nor the README named the degradation — the
 * reader learned about it from silent behaviour. These pins are the pair
 * guard: the README bullet's claims ("required, and declared as ...",
 * "suggested, and declared as ...") must MATCH the manifest arrays, and
 * the capability section must exist with its verbs in it. Drop either
 * side and this reddens — documentation drift here is a capability lie,
 * not a typo.
 */
final class ComposerSuggestSurfaceTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../composer.json';

    private const SECTION = '## Terminal and platform capabilities';

    /**
     * @return array<string, mixed>
     */
    private static function manifest(): array
    {
        $decoded = \json_decode((string) \file_get_contents(self::MANIFEST), true);
        self::assertIsArray($decoded, 'composer.json must be valid JSON (this pin stands in for `composer validate`, which lanes may not run)');

        return $decoded;
    }

    public function testHardRequirementsAreDeclaredInRequire(): void
    {
        $require = self::manifest()['require'];
        // The README Requirements bullets promise these two by name; the
        // 2026-08 hardening pass made intl required precisely because the
        // prose said so while the manifest did not.
        foreach (['ext-mbstring', 'ext-intl'] as $extension) {
            $this->assertArrayHasKey($extension, $require, "README declares {$extension} required-and-declared; require must agree");
        }
    }

    public function testSuggestCarriesExactlyTheThreeDocumentedEntries(): void
    {
        $suggest = self::manifest()['suggest'] ?? [];
        $this->assertSame(
            ['ext-pcntl', 'ext-ffi', 'sugarcraft/sugar-colorprofile'],
            array_keys($suggest),
            'the suggested-capability surface is a named, reviewed set; a fourth entry means the README grew a matching section first',
        );
    }

    public function testThePcntlSuggestEntryNamesWhatDegrades(): void
    {
        $entry = (string) (self::manifest()['suggest']['ext-pcntl'] ?? '');
        foreach (['signal', 'suspend', 'resize'] as $verb) {
            $this->assertStringContainsStringIgnoringCase($verb, $entry, "ext-pcntl suggest text must name the {$verb} leg — the whole point of DS-04 is that the loss is otherwise invisible");
        }
        // And it must not oversell: pcntl is POSIX-only.
        $this->assertStringContainsStringIgnoringCase('posix', $entry);
    }

    public function testTheReadmeCarriesTheCapabilitySectionAndItsVerbs(): void
    {
        $readme    = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $start     = \strpos($readme, self::SECTION);
        $this->assertNotFalse($start, self::SECTION . ' must exist — sc-37 consolidated the degradation story into exactly one section');

        $section = \substr($readme, $start, \strpos($readme, "\n## ", $start + \strlen(self::SECTION)) - $start);
        foreach (['pcntl', 'SIGWINCH', 'Ctrl-Z', 'pcntl_signal_get_handler', 'WindowsBackend', 'PosixBackend', 'stream_select', 'LAST_RESORT_SIZE'] as $phrase) {
            $this->assertStringContainsString($phrase, $section, "the capability section must still speak of {$phrase}");
        }

        // The README and the manifest are one story: the pcntl bullet in
        // Requirements must exist between the intro and the section.
        $this->assertMatchesRegularExpression(
            '/`pcntl` —\s*\*\*suggested, not required/',
            $readme,
            'the Requirements bullet is the pointer into the section; removing either half leaves the other dangling',
        );
    }

    public function testTheSectionSitsInsideRequirementsOrdering(): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertLessThan(
            (int) \strpos($readme, '## Environment variables'),
            (int) \strpos($readme, self::SECTION),
            'platform capability reads before the env-var roster it references',
        );
    }
}

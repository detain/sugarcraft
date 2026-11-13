<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;

/**
 * Documentation is a pinned surface. These arms guard the literals the
 * docs-fix-era review found false: the invented elision-less hunk header,
 * the founding claim that every SugarCraft patch surface shares this one
 * implementation, phantom {@see} targets, and the README quickstart's
 * rendered output. They re-derive from the shipped files (and the engine
 * itself) so a future edit that reintroduces any of those shapes goes red
 * here, not in a human review.
 */
final class DocsTruthTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /** The shape the library NEVER emits: GNU and git both drop a `,1` count. */
    private const FALSE_ELISION_LITERAL = '@@ -1,1 +1,1 @@';

    /** The overclaim the docs-fix era deleted (only the scan half unifies; the gutter view stays app-side). */
    private const FALSE_SHARED_CLAIM = 'shares one faithful implementation';

    /** M1: the Fidelity bullet's generalisation the scanner does not honour. */
    private const FALSE_EXTENDED_HEADER_CLAIM = 'extended header lines scan as file headers';

    private static function readme(): string
    {
        return (string) file_get_contents(self::ROOT . '/README.md');
    }

    private static function siteBody(): string
    {
        return (string) file_get_contents(self::ROOT . '/../docs/_data/sugar-diff.body.html');
    }

    public function test_the_docs_never_print_the_elision_less_hunk_header(): void
    {
        foreach ([self::readme(), self::siteBody()] as $index => $document) {
            self::assertStringNotContainsString(
                self::FALSE_ELISION_LITERAL,
                $document,
                "document {$index} teaches a header shape the writer never emits",
            );
        }
    }

    public function test_the_readme_quickstart_output_is_what_the_engine_renders(): void
    {
        $expected = "--- a/file.txt\n+++ b/file.txt\n@@ -1 +1 @@\n-Hello World\n+Hello PHP\n";

        self::assertSame($expected, Diff::compute("Hello World\n", "Hello PHP\n")->unified('file.txt'));

        foreach ([self::readme(), self::siteBody()] as $document) {
            self::assertStringContainsString("// @@ -1 +1 @@\n// -Hello World\n// +Hello PHP", $document);
        }
    }

    public function test_the_docs_do_not_claim_one_shared_surface_implementation(): void
    {
        self::assertStringNotContainsString(self::FALSE_SHARED_CLAIM, self::readme());
        // …and both docs now state the D1a fold as LANDED: the gutter
        // delegates, so the old own-copy disclosure must be gone while
        // delegation wording is present in every doc surface.
        foreach ([self::readme(), self::siteBody()] as $document) {
            self::assertStringNotContainsString(
                'keeps its own copy',
                $document,
                'the pre-fold own-copy disclosure must not survive the landed D1a fold',
            );
            self::assertTrue(
                stripos($document, 'delegat') !== false,
                'the docs must state the DiffGutter delegation as landed',
            );
        }
    }

    public function test_the_readme_states_the_true_extended_header_rule(): void
    {
        // The scanner recognises `diff `, `index ` and out-of-hunk `--- `/
        // `+++ ` only; `similarity index`/`rename from`/`old mode` rows carry
        // none of those prefixes, so the old blanket claim was false and the
        // corrected rule must stay on one physical line (the wrap-sensitive
        // needle below).
        $readme = self::readme();

        self::assertStringNotContainsString(self::FALSE_EXTENDED_HEADER_CLAIM, $readme);
        self::assertStringContainsString(
            'scanned as unnumbered out-of-hunk rows',
            $readme,
            'the corrected extended-header rule must live in the README, unwrapped',
        );
    }

    public function test_the_source_carries_no_phantom_method_sees_targets(): void
    {
        // Diff retains hunks as a readonly PROPERTY and materializes the row
        // sequence through the derived lines() accessor (sugar-diff-021-era);
        // an unresolvable {@see} is a docs time-bomb — neither the never-
        // existing bare-method spellings nor the pre-laziness property shape
        // may creep back into a docblock.
        $phantoms = ['{@see hunks()}', '{@see unified()}', '{@see Diff::$lines}'];

        foreach (self::sourceFiles() as $file => $text) {
            foreach ($phantoms as $phantom) {
                self::assertStringNotContainsString($phantom, $text, $file);
            }
        }
    }

    /** @return array<string,string> every shipped source file, keyed by path */
    private static function sourceFiles(): array
    {
        $files = [];
        $walk = static function (string $dir) use (&$walk, &$files): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (is_dir($path)) {
                    $walk($path);
                } elseif (str_ends_with($path, '.php')) {
                    $files[$path] = (string) file_get_contents($path);
                }
            }
        };
        $walk(self::ROOT . '/src');

        self::assertGreaterThan(5, count($files), 'the source walk must see the shipped files');

        return $files;
    }

    public function test_the_caliber_supersession_marker_survives(): void
    {
        // The reversed zero-anchor rule is corrected by APPENDING a dated
        // supersession (the file's own never-delete law) — the marker is what
        // tells readers the quoted shape is the inverted one.
        $learnings = (string) file_get_contents(self::ROOT . '/CALIBER_LEARNINGS.md');

        self::assertStringContainsString('SUPERSEDED', $learnings);
        self::assertStringContainsString('must never be', $learnings);
    }
}

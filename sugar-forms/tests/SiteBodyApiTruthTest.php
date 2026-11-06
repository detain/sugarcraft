<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The site page (docs/_data/sugar-forms.body.html) is documentation nobody
 * shipped a guard for: the 2026-11 docs sweep killed a whole phantom family
 * (Form::getValue, ->withValidate, the "pink" theme, Date::withWidth-style
 * misattributions) by hand, README only. This test is the structural cure:
 * every `Class::method(` mention, every `->method(` attachment, and every
 * API-table row of the site body must resolve against the real
 * SugarCraft\Forms\* surface, fail-closed on anything that does not.
 *
 * Quickstart <pre> fences are deliberately out of scope — they are executable
 * examples kept honest by the README sibling guard, not per-symbol promises.
 * Class names resolve from a walk of src/ (namespace = SugarCraft\Forms\ +
 * path), so a renamed class with stale docs reddens here, not in a browser.
 */
final class SiteBodyApiTruthTest extends TestCase
{
    /** @var array<string, string>|null normalized mention-name => FQCN */
    private static ?array $classMap = null;

    /** @var list<string>|null every resolvable type in the package */
    private static ?array $surface = null;

    private function bodyPath(): string
    {
        return dirname(__DIR__, 2) . '/docs/_data/sugar-forms.body.html';
    }

    private function body(): string
    {
        $path = $this->bodyPath();
        if (!is_file($path)) {
            $this->markTestSkipped('monorepo docs tree not present in this clone');
        }

        return (string) file_get_contents($path);
    }

    /**
     * Body minus the fenced quickstart blocks, with HTML entities decoded so
     * `-&gt;` attachments match the same regex as raw `->` ones.
     */
    private function proseBody(): string
    {
        $stripped = preg_replace('~<pre.*?</pre>~s', '', $this->body());
        $this->assertIsString($stripped, 'pre-fence strip must not fail');

        return html_entity_decode((string) $stripped, ENT_QUOTES);
    }

    /**
     * @return array<string, string>
     */
    private function classMap(): array
    {
        if (self::$classMap !== null) {
            return self::$classMap;
        }

        $map = [];
        $leafCounts = [];
        foreach ($this->formsSurface() as $fqcn) {
            $relative = str_replace('\\', '/', substr($fqcn, strlen('SugarCraft\\Forms\\')));
            $map[$relative] = $fqcn; // qualified path form, e.g. "Field/Input"
            $leaf = substr($relative, (int) strrpos($relative, '/') + 1);
            $leafCounts[$leaf] = ($leafCounts[$leaf] ?? 0) + 1;
            if ($leafCounts[$leaf] === 1) {
                $map[$leaf] = $fqcn;
            } else {
                unset($map[$leaf]); // ambiguous leaf: prose must use the path form
            }
        }

        return self::$classMap = $map;
    }

    /**
     * @return list<string>
     */
    private function formsSurface(): array
    {
        if (self::$surface !== null) {
            return self::$surface;
        }

        $surface = [];
        $srcRoot = dirname(__DIR__) . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getRealPath(), strlen($srcRoot) + 1, -4);
            $fqcn = 'SugarCraft\\Forms\\' . str_replace('/', '\\', $relative);
            if (class_exists($fqcn) || interface_exists($fqcn)) {
                $surface[] = $fqcn;
            }
        }
        $this->assertNotEmpty($surface, 'src/ walk must resolve at least one type');
        sort($surface);

        return self::$surface = $surface;
    }

    public function testEveryQualifiedClassMethodMentionResolves(): void
    {
        $matches = [];
        preg_match_all(
            '~(?<![\w\\\\$])([A-Z][A-Za-z0-9]*)::([a-z_]\w*)\(~',
            $this->proseBody(),
            $matches,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($matches, 'scrape must find the known Class::method mentions');

        $map = $this->classMap();
        $checked = [];
        foreach ($matches as $match) {
            [$class, $method] = [$match[1], $match[2]];
            if ($method === 'class' || isset($checked["$class::$method"])) {
                continue;
            }
            $checked["$class::$method"] = true;
            $this->assertArrayHasKey(
                $class,
                $map,
                "site body mentions {$class}::{$method}() but no such type exists in SugarCraft\\Forms"
            );
            $this->assertTrue(
                method_exists($map[$class], $method),
                "site body mentions {$class}::{$method}() — phantom: not on {$map[$class]}"
            );
        }
    }

    public function testEveryAttachedMethodMentionLivesOnSomeFormsType(): void
    {
        $matches = [];
        preg_match_all('~->([a-z_]\w*)\(~', $this->proseBody(), $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        sort($names);
        $this->assertNotEmpty($names, 'scrape must find the known ->method mentions');

        foreach ($names as $name) {
            $found = false;
            foreach ($this->formsSurface() as $fqcn) {
                if (method_exists($fqcn, $name)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                "site body attaches ->{$name}() but no SugarCraft\\Forms\\* type declares it"
            );
        }
    }

    public function testEveryApiTableRowResolvesAgainstTheRealSurface(): void
    {
        $rows = [];
        preg_match_all('~<tr><td>(.*?)</td><td>(.*?)</td>~s', $this->body(), $rows, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(20, count($rows), 'API table scrape must see every row');

        $map = $this->classMap();
        foreach ($rows as $row) {
            $class = str_replace('\\', '/', trim(preg_replace('~\s*\(.*$~s', '', strip_tags($row[1])) ?? ''));
            $this->assertArrayHasKey(
                $class,
                $map,
                "API table row names class '{$class}' which is not in SugarCraft\\Forms"
            );
            $sig = html_entity_decode(strip_tags($row[2]), ENT_QUOTES);
            $methods = $this->methodTokensFromSignature($sig);
            if ($methods === []) {
                // Bare-slot rows (Theme `errorSummary`) promise a property.
                $bare = trim(preg_replace('~[^A-Za-z0-9_].*$~s', '', $sig) ?? '');
                $this->assertNotSame('', $bare, "unparseable API cell for {$class}");
                $this->assertTrue(
                    property_exists($map[$class], $bare),
                    "API table promises {$class}->{$bare} but the property does not exist"
                );
                continue;
            }
            foreach ($methods as $method) {
                $this->assertTrue(
                    method_exists($map[$class], $method),
                    "API table promises {$class}::{$method}() — phantom: not on {$map[$class]}"
                );
            }
        }
    }

    /**
     * Method names are identifiers whose parenthesis opens at bracket depth
     * zero, so nested parameter-type groups (`withHideFunc(?\Closure(array):
     * bool)` promises `new` + `withHideFunc`, never `Closure`) cannot masquerade
     * as call sites.
     *
     * @return list<string>
     */
    private function methodTokensFromSignature(string $sig): array
    {
        $tokens = [];
        $depth = 0;
        $length = strlen($sig);
        for ($i = 0; $i < $length; $i++) {
            $char = $sig[$i];
            if ($char === '(') {
                $depth++;
                // Walk back over whitespace to the identifier that owns this call.
                $j = $i - 1;
                while ($j >= 0 && ctype_space($sig[$j])) {
                    $j--;
                }
                if ($depth === 1 && $j >= 0 && (ctype_alnum($sig[$j]) || $sig[$j] === '_')) {
                    $end = $j;
                    while ($j >= 0 && (ctype_alnum($sig[$j]) || $sig[$j] === '_')) {
                        $j--;
                    }
                    $token = substr($sig, $j + 1, $end - $j);
                    if (ctype_alpha($token[0]) || $token[0] === '_') {
                        $tokens[$token] = true;
                    }
                }
                continue;
            }
            if ($char === ')') {
                $depth = max(0, $depth - 1);
            }
        }

        return array_keys($tokens);
    }
}

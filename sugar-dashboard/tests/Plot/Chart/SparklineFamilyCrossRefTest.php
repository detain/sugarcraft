<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the sibling-cross-reference law for the four same-named-shape
 * sparkline classes (findings sugar-dashboard-012/196): every class docblock
 * names the whole family with its first-arg semantics, the README roster
 * lists only methods that actually exist, and the flagship examples use
 * SparklineBar directly instead of aliasing it as Sparkline.
 */
final class SparklineFamilyCrossRefTest extends TestCase
{
    /** @var list<string> */
    private const FAMILY = ['Sparkline', 'SparklineBar', 'SparklineArea', 'SparkArea'];

    private static function srcPath(string $class): string
    {
        return dirname(__DIR__, 3) . '/src/Plot/Chart/' . $class . '.php';
    }

    public static function provideFamily(): iterable
    {
        foreach (self::FAMILY as $class) {
            yield $class => [$class];
        }
    }

    /**
     * Every sibling class docblock lists all four family members so a
     * reader landing on any one of them sees the flipped first-arg table.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFamily')]
    public function testEachDocblockNamesEverySibling(string $class): void
    {
        $doc = (string) file_get_contents(self::srcPath($class));
        foreach (self::FAMILY as $sibling) {
            $this->assertStringContainsString(
                $sibling,
                $doc,
                "{$class} docblock must cross-reference sibling {$sibling} (finding 196)"
            );
        }
        $this->assertStringContainsString('sugar-dashboard-196', $doc);
    }

    /**
     * The README table row for each family member may only advertise
     * public methods that exist — copy-pasting a documented call must not
     * TypeError (the old row gave Sparkline a withData() it never had).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('provideFamily')]
    public function testReadmeRowAdvertisesOnlyRealMethods(string $class): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');
        $row = null;
        foreach (explode("\n", $readme) as $line) {
            if (str_starts_with(trim($line), '| `' . $class . '`')) {
                $row = $line;
                break;
            }
        }
        $this->assertNotNull($row, "README lost the {$class} row");

        $public = array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass('SugarCraft\\Dashboard\\Plot\\Chart\\' . $class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        preg_match_all('/`(with[A-Za-z]+|push[A-Za-z]*)\(\)/', $row, $tokens);
        $this->assertNotEmpty($tokens[1], "README row for {$class} advertises no setters");
        foreach ($tokens[1] as $token) {
            $this->assertContains($token, $public, "README row {$class} lists non-existent {$token}()");
        }
    }

    public function testSparklineRowIsHonestAboutPushApi(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/README.md');
        $this->assertMatchesRegularExpression('/\|\s*`Sparkline`\s*\|.*pushAll\(\)/', $readme);
        $this->assertMatchesRegularExpression('/\|\s*`Sparkline`\s*\|[^\n]*width = 40/', $readme);
    }

    /**
     * The flagship examples must teach the real class name: SparklineBar
     * unprefixed by a lie, with the intended width actually applied.
     */
    public function testFlagshipExamplesUseSparklineBarDirectly(): void
    {
        foreach (['dashboard-complex', 'dashboard-charts'] as $slug) {
            $src = (string) file_get_contents(dirname(__DIR__, 3) . '/examples/' . $slug . '.php');
            $this->assertStringNotContainsString('SparklineBar as Sparkline', $src, "{$slug} still aliases the bar as Sparkline");
            $this->assertMatchesRegularExpression('/SparklineBar::new\(\[[^\]]*\]\)->withWidth\(\d+\)/', $src, "{$slug} must pass data to the real ctor and width via withWidth");
        }
    }
}

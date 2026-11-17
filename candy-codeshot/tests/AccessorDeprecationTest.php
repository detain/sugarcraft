<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\StringOutputWriter;

/**
 * Pins the end of the accessor-deprecation era (2026-10-24, ruling 3b, W-G1).
 *
 * StringOutputWriter::getResult() was the `get`-prefixed BC forwarder for
 * result(). It had no production caller, so the forwarder is removed and
 * result() is the only accessor for the accumulated output. The token census
 * below keeps every public get*() out of src/, so the bare-accessor house
 * convention cannot quietly drift back.
 */
final class AccessorDeprecationTest extends TestCase
{
    public function testTheRemovedForwarderIsGone(): void
    {
        $this->assertFalse(\method_exists(StringOutputWriter::class, 'getResult'));
    }

    public function testResultIsThePublicAccessorForTheAccumulatedOutput(): void
    {
        $writer = new StringOutputWriter();
        $this->assertSame('', $writer->result());
        $writer->write('alpha');
        $writer->write('-bravo');
        $writer->flush();
        $this->assertSame('alpha-bravo', $writer->result());

        $method = new \ReflectionMethod(StringOutputWriter::class, 'result');
        $this->assertTrue($method->isPublic());
        $this->assertSame('string', (string) $method->getReturnType());
    }

    /**
     * Census leg: every public get*() declared under src/ is a violation of
     * the bare-accessor convention. The swept forwarder is gone, so the
     * expected list is empty.
     */
    public function testNoPublicGetAccessorRemainsInSrc(): void
    {
        $this->assertSame([], self::srcGetAccessors(), 'public get*() accessors reappeared in src/');
    }

    /**
     * Docs leg: the README accessor section names the bare accessor and
     * stays free of the removed spelling.
     */
    public function testTheReadmeAccessorSectionNamesTheBareAccessor(): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertStringContainsString('`StringOutputWriter::result()`', $readme);
        $this->assertStringNotContainsString('getResult', $readme);
    }

    /**
     * Token walk of src/: returns "fileBasename::name" for every public
     * get*() function declaration, sorted.
     *
     * @return list<string>
     */
    private static function srcGetAccessors(): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize((string) \file_get_contents($file->getPathname()));
            $count = \count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]->id !== T_FUNCTION) {
                    continue;
                }
                $name = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->id === T_WHITESPACE) {
                        continue;
                    }
                    $name = $tokens[$j]->text;
                    break;
                }
                if (\preg_match('/^get[A-Z]/', $name) !== 1) {
                    continue;
                }
                $visible = true;
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (
                        $tokens[$k]->id === T_WHITESPACE
                        || $tokens[$k]->id === T_PUBLIC
                        || $tokens[$k]->id === T_STATIC
                        || $tokens[$k]->id === T_FINAL
                        || $tokens[$k]->id === T_ABSTRACT
                        || (\defined('T_READONLY') && $tokens[$k]->id === T_READONLY)
                    ) {
                        continue;
                    }
                    if ($tokens[$k]->id === T_PRIVATE || $tokens[$k]->id === T_PROTECTED) {
                        $visible = false;
                    }
                    break;
                }
                if ($visible) {
                    $names[] = \basename($file->getFilename(), '.php') . '::' . $name;
                }
            }
        }
        \sort($names);
        return $names;
    }
}

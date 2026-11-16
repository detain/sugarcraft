<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Database;
use SugarCraft\Kv\Store;

/**
 * Pins the removal of the 2026-10-24 BC forwarders.
 *
 * Database::getRaw() and Store::getFile() were one-line forwarders to
 * raw() and saveTo(). Nothing in the monorepo called them, so they were
 * removed on 2026-11-16, ahead of the REMOVED-IN: 2.0.0 schedule. The
 * canonical methods now carry the behaviour the forwarders had, and the
 * census below stops a public get*() accessor from drifting back into src/.
 */
final class AccessorDeprecationTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function removedAccessors(): array
    {
        return [
            'Database::getRaw' => [Database::class, 'getRaw'],
            'Store::getFile' => [Store::class, 'getFile'],
        ];
    }

    /**
     * @return array{0: Database, 1: string}
     */
    private static function seededDatabase(): array
    {
        $base = \tempnam(\sys_get_temp_dir(), 'kv-dep-');
        \unlink($base);
        $db = new Database($base . '.db', 'test');
        $db->set('alpha', 'first');
        return [$db, $base];
    }

    /**
     * @return array{0: Store, 1: string}
     */
    private static function seededStore(): array
    {
        $dir = \sys_get_temp_dir() . '/kv-dep-store-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        $store = new Store($dir);
        $store->set('avatar', 'raw-bytes', true);
        return [$store, $dir];
    }

    /**
     * @param list<string> $files
     */
    private static function removeFiles(array $files): void
    {
        foreach ($files as $file) {
            @\unlink($file);
        }
    }

    private static function closeDatabase(Database $db, string $base): void
    {
        $db->close();
        self::removeFiles([$base . '.db', $base . '.db-wal', $base . '.db-shm']);
    }

    /**
     * Removal leg: the legacy spellings must not exist on the class at all,
     * so a restored forwarder reddens here rather than passing silently.
     *
     * @param class-string $class
     */
    #[DataProvider('removedAccessors')]
    public function testRemovedSpellingIsGone(string $class, string $legacy): void
    {
        $this->assertFalse(\method_exists($class, $legacy), $class . '::' . $legacy . ' must stay removed');
    }

    /**
     * Canonical leg for raw(): the entry it answers for a present key is the
     * stored value, and a missing key answers null. Entries are returned
     * whether or not they are expired (the expiry filter lives in get()).
     */
    public function testRawAnswersPresentAndMissingKeys(): void
    {
        [$db, $base] = self::seededDatabase();
        try {
            $this->assertSame('first', $db->raw('alpha')?->rawValue());
            $this->assertNull($db->raw('missing'));
        } finally {
            self::closeDatabase($db, $base);
        }
    }

    /**
     * Canonical leg for saveTo(): a hit writes the entry bytes and returns
     * true; a miss returns false and leaves no file behind.
     */
    public function testSaveToWritesTheBytesAndReturnsTheBool(): void
    {
        [$store, $dir] = self::seededStore();
        $out = \tempnam(\sys_get_temp_dir(), 'kv-dep-out-');
        $miss = $out . '.miss';
        try {
            $this->assertTrue($store->saveTo('avatar', $out));
            $this->assertSame($store->get('avatar'), \file_get_contents($out));
            $this->assertFalse($store->saveTo('nope', $miss));
            $this->assertFileDoesNotExist($miss);
        } finally {
            self::removeFiles([$out, $miss]);
            foreach ((\scandir($dir) ?: []) as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_file($dir . '/' . $entry)) {
                    @\unlink($dir . '/' . $entry);
                }
            }
            @\rmdir($dir);
        }
    }

    /**
     * Census leg: no public get[A-Z]*() accessor may exist anywhere in src/.
     * The house rule is bare accessors; the store verbs get/set/list are
     * exempt because they are not a get-prefixed accessor.
     */
    public function testNoPublicGetAccessorExistsInSrc(): void
    {
        $this->assertSame([], self::srcGetAccessors(), 'public get*() accessors found in src/');
    }

    /**
     * Token walk of src/: returns "file::name" for every public get[A-Z]*()
     * function declaration.
     *
     * @return list<string>
     */
    private static function srcGetAccessors(): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(\file_get_contents($file->getPathname()));
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
                $private = false;
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
                        $private = true;
                    }
                    break;
                }
                if (!$private) {
                    $found[] = \basename($file->getFilename(), '.php') . '::' . $name;
                }
            }
        }
        \sort($found);
        return $found;
    }

    /**
     * Docs leg: the README names the canonical replacements for the removed
     * spellings, so a reader is not left pointing at a method that is gone.
     */
    public function testReadmeNamesTheCanonicalReplacements(): void
    {
        $readme = (string) \file_get_contents(\dirname(__DIR__) . '/README.md');
        $this->assertStringContainsString('## Accessor removal (2026-11-16)', $readme);
        $section = (string) \strstr($readme, '## Accessor removal (2026-11-16)');
        $this->assertStringContainsString('`raw()`', $section);
        $this->assertStringContainsString('`saveTo()`', $section);
    }
}

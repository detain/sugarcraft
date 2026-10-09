<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Support;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\MediaKind;
use SugarCraft\Crush\Support\MediaStore;

/**
 * Plan W1.9: the HOME-anchored artifact store — naming palette, 0700/0600
 * settlement, `.partial` + rename atomicity, and the structural ordering pin
 * that a functional mode check cannot substitute for.
 */
final class MediaStoreTest extends TestCase
{
    use HomeSandboxTrait;

    private string $storeRoot = '';

    protected function setUp(): void
    {
        $this->storeRoot = sys_get_temp_dir() . '/mediastore-' . uniqid('', true);
        mkdir($this->storeRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->purgeStoreTree($this->storeRoot);
    }

    public function testDefaultPatternBuildsTheExpectedName(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $artifact = $store->put('PNG', 'png', [
            'date' => '2026-10-09', 'time' => '14-02-33', 'seed' => 12345,
        ]);

        $this->assertSame('2026-10-09-14-02-33-12345-0.png', basename($artifact->path()));
        $this->assertStringStartsWith($this->storeRoot . '/alpha/', $artifact->path());
    }

    public function testGeneratedClockArmsFillWhenTheCallerOmitsThem(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $artifact = $store->put('x', 'png', ['seed' => 7]);
        $name = basename($artifact->path());

        $this->assertStringStartsWith(date('Y-m-d') . '-', $name);
        $this->assertStringContainsString('-7-0.png', $name);
    }

    public function testJobTimestampArmRidesTheClockToo(): void
    {
        $store = MediaStore::forSession('stamp', $this->storeRoot, 'j-[job_timestamp]-[seed]');
        $name = basename($store->put('x', 'png', ['seed' => 3])->path());

        $this->assertSame(14, \strlen(explode('-', $name)[1]));
    }

    public function testUnknownBracketedTokenStaysLiteralAndIsNoted(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot, 'x-[nope]-[seed]');
        $artifact = $store->put('x', 'png', ['seed' => 1]);

        $this->assertSame('x-[nope]-1.png', basename($artifact->path()));
        $this->assertSame(['nope'], $artifact->tokenValues()['unknown_tokens']);
    }

    public function testMissingPatternTokenCollapsesToEmptyWithNote(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot, 'art-[model_name]-[seed]');
        $artifact = $store->put('x', 'png', ['seed' => 4]);

        $this->assertSame('art--4.png', basename($artifact->path()));
        $this->assertSame(['model_name'], $artifact->tokenValues()['missing_tokens']);
    }

    public function testNumericArmsRefuseJunkNamingTheToken(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);

        try {
            $store->put('x', 'png', ['seed' => 'not-a-number']);
            $this->fail('expected an integer-arm refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('seed', $e->getMessage());
        }
    }

    public function testCfgArmAcceptsFloatsAndRejectsText(): void
    {
        $store = MediaStore::forSession('cfg', $this->storeRoot, 'c-[cfg]-[seed]');
        $this->assertSame('c-7.5-2.png', basename($store->put('x', 'png', ['cfg' => 7.5, 'seed' => 2])->path()));

        try {
            $store->put('x', 'png', ['cfg' => 'high', 'seed' => 2]);
            $this->fail('expected a numeric-arm refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('cfg', $e->getMessage());
        }
    }

    public function testOffPaletteTokenKeysAreRefused(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/prompt/');
        $store->put('x', 'png', ['prompt' => 'a filename leak']);
    }

    public function testExtensionDoorRejectsJunk(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        foreach (['', 'png/..', 'waaytoolongext', 'p n g'] as $junk) {
            try {
                $store->put('x', $junk, ['seed' => 1]);
                $this->fail('extension door should refuse ' . var_export($junk, true));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('extension', $e->getMessage());
            }
        }
    }

    public function testArtifactsAreSixZeroZeroAndSessionDirSevenZeroZeroUnderLooseUmask(): void
    {
        $this->assertModesUnderUmask(0o000);
    }

    public function testTheSameModesHoldUnderRestrictiveUmask(): void
    {
        $this->assertModesUnderUmask(0o077);
    }

    public function testCollisionIncrementsTheNumberArm(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $tokens = ['date' => '2026-10-09', 'time' => '14-02-33', 'seed' => 12345];
        $first = $store->put('a', 'png', $tokens);
        $second = $store->put('b', 'png', $tokens);
        $third = $store->put('c', 'png', $tokens + ['number' => 2]);

        $this->assertSame('2026-10-09-14-02-33-12345-0.png', basename($first->path()));
        $this->assertSame('2026-10-09-14-02-33-12345-1.png', basename($second->path()));
        $this->assertSame('2026-10-09-14-02-33-12345-2.png', basename($third->path()));
    }

    public function testPatternWithoutNumberGetsDashSuffixWhenTaken(): void
    {
        $store = MediaStore::forSession('tail', $this->storeRoot, 'fixed-[seed]');
        $a = $store->put('x', 'png', ['seed' => 9]);
        $b = $store->put('x', 'png', ['seed' => 9]);

        $this->assertSame('fixed-9.png', basename($a->path()));
        $this->assertSame('fixed-9-1.png', basename($b->path()));
    }

    public function testLongModelNameIsCappedAtOneTwentyEightAndStaysUnique(): void
    {
        $store = MediaStore::forSession('cap', $this->storeRoot, '[model_name]-[number]');
        $tokens = ['model_name' => str_repeat('x', 200), 'seed' => 5];
        $a = $store->put('x', 'png', $tokens);
        $b = $store->put('x', 'png', $tokens);

        $this->assertSame(128, \strlen(basename($a->path())));
        $this->assertSame(128, \strlen(basename($b->path())));
        $this->assertNotSame(basename($a->path()), basename($b->path()));
    }

    public function testTraversalShapesInSessionIdAreRefusedNotStripped(): void
    {
        foreach (['../evil', 'a/b', 'a\\b', "a\0b", '..x'] as $poison) {
            try {
                MediaStore::forSession($poison, $this->storeRoot);
                $this->fail('session id door should refuse ' . var_export($poison, true));
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('session id', $e->getMessage());
            }
        }
    }

    public function testBareDotAndBlankIdsFallBackToDefault(): void
    {
        $this->assertSame('default', MediaStore::forSession('.', $this->storeRoot)->sessionId());
        $this->assertSame('default', MediaStore::forSession('   ', $this->storeRoot)->sessionId());
    }

    public function testPatternSeparatorsCannotEscapeTheSessionDirectory(): void
    {
        $store = MediaStore::forSession('caged', $this->storeRoot, '../../evil-[seed]');
        $artifact = $store->put('x', 'png', ['seed' => 9]);

        $this->assertStringStartsWith($store->directoryPath() . '/', $artifact->path());
        $this->assertStringContainsString('evil-9.png', basename($artifact->path()));
        $this->assertSame(1, \count($store->list()));
        $this->assertFileDoesNotExist($this->storeRoot . '/evil-9.png');
    }

    public function testSuccessfulPutLeavesNoPartialResidue(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $store->put('x', 'png', ['seed' => 1]);

        $this->assertSame([], $this->partialEntries($store->directoryPath()));
    }

    public function testThrowingWriterLeavesNeitherFinalNorPartial(): void
    {
        $store = MediaStore::forSession(
            'burst',
            $this->storeRoot,
            null,
            static function ($handle, string $bytes): void {
                throw new \RuntimeException('injected mid-write failure');
            },
        );

        try {
            $store->put('x', 'png', ['seed' => 1]);
            $this->fail('the injected writer must surface');
        } catch (\RuntimeException $e) {
            $this->assertSame('injected mid-write failure', $e->getMessage());
        }

        $this->assertSame([], $this->partialEntries($store->directoryPath()));
        $this->assertSame([], $store->list());
    }

    public function testPermissionsAreSettledOnThePartialBeforeThePayloadAndNeverAfterTheRename(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Support/MediaStore.php');

        $publish = self::slicedBody($src, 'private function publish');
        $open = strpos($publish, 'fopen($partial');
        $settle = strpos($publish, '$this->applyPermissions($partial)');
        $payload = strpos($publish, '$write($handle, $bytes)');
        $rename = strpos($publish, 'rename($partial, $target)');

        $this->assertIsInt($open, 'publish() no longer opens the .partial via fopen($partial, …)');
        $this->assertIsInt($settle, 'publish() no longer settles permissions via applyPermissions($partial)');
        $this->assertIsInt($payload);
        $this->assertIsInt($rename, 'publish() no longer publishes with rename($partial, $target)');

        $this->assertLessThan($settle, $open, 'the temp inode must exist before it is permissioned');
        $this->assertLessThan($payload, $settle, 'no payload byte may land before the mode is set');
        $this->assertLessThan($rename, $settle, 'permissions must be settled BEFORE the publish rename');

        $settler = self::slicedBody($src, 'private function applyPermissions');
        $this->assertStringContainsString('chmod($partial, self::FILE_MODE)', $settler);
        $this->assertSame(1, substr_count($src, 'chmod('), 'the temp inode is the only chmod target this class owns');
        $this->assertStringNotContainsString('chmod($target', $src, 'a chmod on the published path is the banned shape');
    }

    public function testListPathForReadAndDeleteRoundTrip(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $a = $store->put('AAA', 'png', ['date' => 'd', 'time' => 't', 'seed' => 1]);
        $store->put('BBBB', 'png', ['date' => 'd', 'time' => 't', 'seed' => 2]);

        $rows = $store->list();
        $this->assertCount(2, $rows);
        $this->assertSame(['d-t-1-0', 'd-t-2-0'], array_column($rows, 'id'));
        $this->assertSame(3, $rows[0]['bytes']);

        $id = basename($a->path(), '.png');
        $this->assertSame($a->path(), $store->pathFor($id));
        $this->assertSame('AAA', $store->read($id));
        $this->assertTrue($store->delete($id));
        $this->assertNull($store->pathFor($id));
        $this->assertNull($store->read($id));
        $this->assertFalse($store->delete($id));
    }

    public function testPathForRefusesSeparatorBearingIds(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);

        $this->expectException(\RuntimeException::class);
        $store->pathFor('../elsewhere');
    }

    public function testSessionsAreIsolatedFromEachOther(): void
    {
        $alpha = MediaStore::forSession('alpha', $this->storeRoot);
        $beta = MediaStore::forSession('beta', $this->storeRoot);
        $alpha->put('x', 'png', ['seed' => 1]);

        $this->assertCount(1, $alpha->list());
        $this->assertSame([], $beta->list());
        $this->assertSame('alpha', $alpha->sessionId());
        $this->assertSame($this->storeRoot . '/beta', $beta->directoryPath());
    }

    public function testDefaultRootIsTheOwnedHomeMediaDirectory(): void
    {
        $home = $this->useHomeSandbox($this->storeRoot . '/home');
        $store = MediaStore::forSession('s1');
        $artifact = $store->put('x', 'png', ['seed' => 1]);

        $this->assertStringStartsWith($home . '/.sugar-crush/media/s1/', $artifact->path());
        $this->assertSame('0700', substr(sprintf('%o', fileperms($store->directoryPath())), -4));
    }

    public function testArtifactCarriesAbsoluteKindAndFilledTokenValues(): void
    {
        $store = MediaStore::forSession('alpha', $this->storeRoot);
        $artifact = $store->put('x', 'mp4', ['seed' => '42', 'steps' => 20], MediaKind::Video);

        $this->assertSame(MediaKind::Video, $artifact->kind());
        $this->assertTrue($artifact->pathIsSet());
        $this->assertSame('42', $artifact->tokenValues()['seed']);
        $this->assertSame('20', $artifact->tokenValues()['steps']);
        $this->assertSame('0', $artifact->tokenValues()['number']);
        $this->assertStringStartsWith('/', $artifact->path());
    }

    /** Both umask polarities must settle to the same 0600/0700 outcome. */
    private function assertModesUnderUmask(int $umask): void
    {
        $previous = umask($umask);
        try {
            $store = MediaStore::forSession('mode', $this->storeRoot);
            $artifact = $store->put('x', 'png', ['seed' => 1]);
            clearstatcache();
            $this->assertSame('0600', substr(sprintf('%o', fileperms($artifact->path())), -4));
            $this->assertSame('0700', substr(sprintf('%o', fileperms($store->directoryPath())), -4));
        } finally {
            umask($previous);
        }
    }

    /** @return list<string> every .partial-suffixed entry in $dir, hidden ones included */
    private function partialEntries(string $dir): array
    {
        $found = [];
        foreach ((array) scandir($dir) as $name) {
            if ($name !== '.' && $name !== '..' && str_ends_with($name, MediaStore::PARTIAL_SUFFIX)) {
                $found[] = $name;
            }
        }

        return $found;
    }

    private function purgeStoreTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) && !is_link($path) ? $this->purgeStoreTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Slice a method body by signature line, up to the first return at method indentation. */
    private static function slicedBody(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail("MediaStore no longer declares {$signature}() — update this pin, do not delete it.");
        }

        $end = strpos($src, "\n    }\n", $start);
        if ($end === false) {
            self::fail("Could not find the end of {$signature}().");
        }

        return substr($src, $start, $end - $start);
    }
}

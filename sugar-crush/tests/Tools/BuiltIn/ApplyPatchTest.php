<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\ApplyPatch;
use SugarCraft\Crush\Tools\Catalog\ToolCatalog;
use SugarCraft\Crush\Tools\Catalog\ToolPermissionClass;
use SugarCraft\Crush\Tools\ReadLedger;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Roadmap 3.I-3: `ApplyPatch` at the tool boundary — several files changed
 * in one call, every hunk placed by Edit's matcher chain, and nothing written
 * unless everything can be.
 */
final class ApplyPatchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/crush_applypatch_' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    public function testItIsAWriteClassBuiltInAfterEveryEarlierPosition(): void
    {
        self::assertSame(ToolPermissionClass::Write, ToolCatalog::permissionOf('ApplyPatch'));
        self::assertContains('ApplyPatch', ToolCatalog::names());
        self::assertSame('ApplyPatch', (new ApplyPatch())->name());
        self::assertSame(['patch', 'description'], (new ApplyPatch())->inputSchema()['required']);
    }

    public function testAddUpdateMoveAndDeleteLandTogether(): void
    {
        $this->put('cart.php', "<?php\nfunction total() {\n    return 1;\n}\n");
        $this->put('rename.txt', "keep\nchange\n");
        $this->put('gone.txt', "bye\n");

        $result = $this->apply(<<<'PATCH'
            *** Begin Patch
            *** Add File: lib/new.txt
            +hello
            *** Update File: cart.php
            @@ function total() {
            -    return 1;
            +    return 2;
            *** Update File: rename.txt
            *** Move to: moved/renamed.txt
             keep
            -change
            +changed
            *** Delete File: gone.txt
            *** End Patch
            PATCH);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("hello\n", $this->get('lib/new.txt'));
        self::assertSame("<?php\nfunction total() {\n    return 2;\n}\n", $this->get('cart.php'));
        self::assertSame("keep\nchanged\n", $this->get('moved/renamed.txt'));
        self::assertFileDoesNotExist($this->dir . '/rename.txt');
        self::assertFileDoesNotExist($this->dir . '/gone.txt');

        $content = $result->content();
        self::assertStringStartsWith('Patch applied:', $content);
        self::assertStringContainsString("- added {$this->dir}/lib/new.txt (+1 -0 lines)", $content);
        self::assertStringContainsString("- updated {$this->dir}/cart.php (+1 -1 lines)", $content);
        self::assertStringContainsString("- moved {$this->dir}/rename.txt to {$this->dir}/moved/renamed.txt", $content);
        self::assertStringContainsString("- deleted {$this->dir}/gone.txt (+0 -1 lines)", $content);
        self::assertNotNull($result->diff());
        self::assertStringContainsString('+    return 2;', (string) $result->diff());
    }

    public function testOneFailingSectionLeavesEveryFileUntouched(): void
    {
        $this->put('a.txt', "one\n");
        $this->put('b.txt', "two\n");

        $result = $this->apply("*** Begin Patch\n*** Update File: a.txt\n-one\n+ONE\n*** Add File: c.txt\n+new\n*** Update File: b.txt\n-not there\n+x\n*** End Patch");

        self::assertTrue($result->isError());
        self::assertStringContainsString('section 3 (b.txt)', $result->content());
        self::assertStringContainsString('no file changed', $result->content());
        self::assertSame("one\n", $this->get('a.txt'));
        self::assertFileDoesNotExist($this->dir . '/c.txt');
    }

    public function testAFailedWriteRollsBackTheFilesAlreadyWritten(): void
    {
        $this->put('a.txt', "one\n");
        $this->put('b.txt', "two\n");
        $seam = static function ($handle, string $payload): void {
            if (str_contains($payload, 'FAIL')) {
                throw new \RuntimeException('disk full');
            }
            fwrite($handle, $payload);
        };

        $result = (new ApplyPatch($this->dir, writeSeam: $seam))->execute(['patch' => "*** Begin Patch\n*** Update File: a.txt\n-one\n+ONE\n*** Add File: sub/c.txt\n+c\n*** Update File: b.txt\n-two\n+FAIL\n*** End Patch"]);

        self::assertTrue($result->isError());
        self::assertStringContainsString('put back', $result->content());
        self::assertSame("one\n", $this->get('a.txt'), 'the file written first is restored');
        self::assertSame("two\n", $this->get('b.txt'));
        self::assertDirectoryDoesNotExist($this->dir . '/sub', 'a directory the patch created is removed again');
    }

    public function testALooseMatchAppliesThroughEditsChainAndNamesTheStage(): void
    {
        $this->put('f.php', "if (\$a) {\n        call();\n        done();\n}\n");

        $result = $this->apply("*** Begin Patch\n*** Update File: f.php\n@@\n     call();\n-    done();\n+    finish();\n*** End Patch");

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("if (\$a) {\n        call();\n        finish();\n}\n", $this->get('f.php'));
        self::assertStringContainsString('uniform-indent match', $result->content());
    }

    public function testAnAmbiguousHunkIsRefusedAndAnAnchorSettlesIt(): void
    {
        $text = "function a() {\n    return 1;\n}\nfunction b() {\n    return 1;\n}\n";
        $this->put('f.php', $text);

        $refused = $this->apply("*** Begin Patch\n*** Update File: f.php\n-    return 1;\n+    return 2;\n*** End Patch");
        self::assertTrue($refused->isError());
        self::assertStringContainsString('matches 2 places', $refused->content());
        self::assertStringContainsString('lines 2, 5', $refused->content());
        self::assertSame($text, $this->get('f.php'));

        $anchored = $this->apply("*** Begin Patch\n*** Update File: f.php\n@@ function b() {\n-    return 1;\n+    return 2;\n*** End Patch");
        self::assertFalse($anchored->isError(), $anchored->content());
        self::assertSame("function a() {\n    return 1;\n}\nfunction b() {\n    return 2;\n}\n", $this->get('f.php'));
    }

    public function testHunksApplyInOrderEachAfterThePreviousOne(): void
    {
        // `mark` is in the file twice, but after the first hunk only once.
        $this->put('f.txt', "x\nmark\ny\nmark\n");

        $result = $this->apply("*** Begin Patch\n*** Update File: f.txt\n x\n-mark\n+first\n@@\n-mark\n+second\n*** End Patch");

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("x\nfirst\ny\nsecond\n", $this->get('f.txt'));
    }

    public function testEndOfFilePicksTheLastOccurrence(): void
    {
        $this->put('f.txt', "end\nmiddle\nend\n");

        $result = $this->apply("*** Begin Patch\n*** Update File: f.txt\n-end\n+END\n*** End of File\n*** End Patch");

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("end\nmiddle\nEND\n", $this->get('f.txt'));
    }

    public function testAFileWithoutATrailingNewlineKeepsItsShape(): void
    {
        $this->put('f.txt', "a\nb");

        $result = $this->apply("*** Begin Patch\n*** Update File: f.txt\n a\n-b\n+c\n*** End Patch");

        self::assertFalse($result->isError(), $result->content());
        self::assertSame("a\nc", $this->get('f.txt'));
    }

    public function testAPureInsertionNeedsAPlace(): void
    {
        $this->put('f.txt', "a\nb\n");

        $refused = $this->apply("*** Begin Patch\n*** Update File: f.txt\n+new\n*** End Patch");
        self::assertTrue($refused->isError());
        self::assertStringContainsString('no telling where', $refused->content());

        $anchored = $this->apply("*** Begin Patch\n*** Update File: f.txt\n@@ a\n+new\n*** End Patch");
        self::assertFalse($anchored->isError(), $anchored->content());
        self::assertSame("a\nnew\nb\n", $this->get('f.txt'));
    }

    public function testAddOfAnExistingFileAndUpdateOfAMissingOneAreRefused(): void
    {
        $this->put('there.txt', "x\n");

        $add = $this->apply("*** Begin Patch\n*** Add File: there.txt\n+y\n*** End Patch");
        self::assertTrue($add->isError());
        self::assertStringContainsString('already exists', $add->content());
        self::assertSame("x\n", $this->get('there.txt'));

        $update = $this->apply("*** Begin Patch\n*** Update File: missing.txt\n-a\n+b\n*** End Patch");
        self::assertTrue($update->isError());
        self::assertStringContainsString('file not found', $update->content());
        self::assertStringContainsString('Add File', $update->content());
    }

    public function testAPathOutsideTheRootIsRefused(): void
    {
        $result = $this->apply("*** Begin Patch\n*** Add File: ../escape.txt\n+x\n*** End Patch");

        self::assertTrue($result->isError());
        self::assertStringContainsString('outside the workspace', $result->content());
        self::assertFileDoesNotExist(\dirname($this->dir) . '/escape.txt');
    }

    public function testAMalformedPatchIsRefusedWithItsLine(): void
    {
        $result = $this->apply("*** Begin Patch\n*** Update File: a\nbad\n*** End Patch");

        self::assertTrue($result->isError());
        self::assertStringContainsString('does not parse: patch line 3', $result->content());
    }

    public function testAFileChangedSinceItWasReadRefusesThePatch(): void
    {
        $this->put('f.txt', "one\ntwo\n");
        $ledger = ReadLedger::new();
        $ledger->record($this->dir . '/f.txt');
        $this->put('f.txt', "one\ntwo\nthree\n");

        $tool = new ApplyPatch($this->dir, readLedger: $ledger);
        self::assertStringContainsString('changed on disk', $tool->description());

        $stale = $tool->execute(['patch' => "*** Begin Patch\n*** Update File: f.txt\n-two\n+TWO\n*** End Patch"]);
        self::assertTrue($stale->isError());
        self::assertStringContainsString('changed on disk since you last read it', $stale->content());

        $ledger->record($this->dir . '/f.txt');
        $fresh = $tool->execute(['patch' => "*** Begin Patch\n*** Update File: f.txt\n-two\n+TWO\n*** Delete File: gone.txt\n*** End Patch"]);
        self::assertTrue($fresh->isError(), 'gone.txt does not exist');

        $ok = $tool->execute(['patch' => "*** Begin Patch\n*** Update File: f.txt\n-two\n+TWO\n*** End Patch"]);
        self::assertFalse($ok->isError(), $ok->content());
        self::assertNull($ledger->staleness($this->dir . '/f.txt'), 'the patch is the model\'s new picture of the file');
    }

    public function testADeletedFileLeavesTheLedger(): void
    {
        $this->put('f.txt', "x\n");
        $ledger = ReadLedger::new();
        $ledger->record($this->dir . '/f.txt');

        $result = (new ApplyPatch($this->dir, readLedger: $ledger))->execute(['patch' => "*** Begin Patch\n*** Delete File: f.txt\n*** End Patch"]);

        self::assertFalse($result->isError(), $result->content());
        self::assertSame([], $ledger->changedSinceRead(), 'the model\'s own delete is not reported back to it as a change');
    }

    private function apply(string $patch): ToolResult
    {
        return (new ApplyPatch($this->dir))->execute(['patch' => $patch, 'description' => 'test']);
    }

    private function put(string $relative, string $bytes): void
    {
        file_put_contents($this->dir . '/' . $relative, $bytes);
    }

    private function get(string $relative): string
    {
        return (string) file_get_contents($this->dir . '/' . $relative);
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..' && \is_string($entry)) {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}

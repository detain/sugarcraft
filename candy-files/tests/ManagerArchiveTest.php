<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Manager;
use SugarCraft\Files\Support\Archive;
use SugarCraft\Files\Tests\Support\FsTestCase;
use SugarCraft\Files\Tests\Support\TarBuilder;

/**
 * CF-33: read-only archive browsing + extract. Fixtures are real archives
 * on disk; the hostile shapes (zip-slip names, symlink records) are built
 * byte-for-byte so the safety contract is proven against the wire format,
 * not a library's friendly view of it.
 */
final class ManagerArchiveTest extends FsTestCase
{
    private string $src;
    private string $dst;

    protected function setUp(): void
    {
        parent::setUp();
        Archive::forget();
        $this->src = $this->tmp . '/src';
        $this->dst = $this->tmp . '/dst';
        mkdir($this->src);
        mkdir($this->dst);
    }

    protected function tearDown(): void
    {
        Archive::useLimits(null, null, null);
        parent::tearDown();
    }

    /** @return list<array{name:string,type?:string,body?:string}> */
    private function sampleRecords(): array
    {
        return [
            ['name' => 'readme.txt', 'body' => "hello\n"],
            ['name' => 'docs/', 'type' => '5'],
            ['name' => 'docs/guide.md', 'body' => '# guide'],
            ['name' => 'docs/deep/leaf.txt', 'body' => 'leaf'],
        ];
    }

    private function makeZip(string $path, array $files): void
    {
        $z = new \ZipArchive();
        $this->assertTrue($z->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($files as $name => $body) {
            $body === null ? $z->addEmptyDir($name) : $z->addFromString($name, $body);
        }
        $z->close();
    }

    private function makeTar(string $path, array $records, string $kind = 'tar'): void
    {
        $bytes = TarBuilder::build($records);
        file_put_contents($path, match ($kind) {
            'tgz' => gzencode($bytes),
            'tbz' => bzcompress($bytes),
            default => $bytes,
        });
    }

    private function mgr(): Manager
    {
        return Manager::start($this->src, $this->dst, \SugarCraft\Files\FsLister::lister());
    }

    private function names(Manager $m): array
    {
        return array_map(static fn($e) => $e->name, $m->activePane()->entries);
    }

    private function open(Manager $m, string $name): Manager
    {
        return $this->key($this->cursorOn($m, $name), KeyType::Enter);
    }

    // --- format / path helpers -------------------------------------------

    public function testFormatDetectionIsByExtensionCaseInsensitive(): void
    {
        $this->assertSame('zip', Archive::format('A.ZIP'));
        $this->assertSame('tar', Archive::format('x.tar'));
        $this->assertSame('tgz', Archive::format('x.tar.gz'));
        $this->assertSame('tgz', Archive::format('x.TGZ'));
        $this->assertSame('tbz', Archive::format('x.tar.bz2'));
        $this->assertSame('tbz', Archive::format('x.tbz2'));
        $this->assertNull(Archive::format('notes.gz'));
        $this->assertNull(Archive::format('readme.txt'));
    }

    public function testNormalizeRejectsEveryEscapeShape(): void
    {
        foreach (['../evil', 'a/../../evil', '/abs/path', 'C:\\win', 'a\\..\\b', "nul\0byte", '', '..', 'a/..'] as $bad) {
            $this->assertNull(Archive::normalize($bad), json_encode($bad));
        }
        $this->assertSame('a/b', Archive::normalize('./a//b/'));
        $this->assertSame('a/b', Archive::normalize('a\\b'));
    }

    public function testSplitRequiresARealArchiveFile(): void
    {
        $this->makeZip($this->src . '/a.zip', ['f.txt' => 'x']);
        $this->assertSame([$this->src . '/a.zip', ''], Archive::split($this->src . '/a.zip!'));
        $this->assertSame([$this->src . '/a.zip', 'docs/x'], Archive::split($this->src . '/a.zip!/docs/x/'));
        mkdir($this->src . '/dir.zip!');
        $this->assertNull(Archive::split($this->src . '/dir.zip!/inner'), 'a real directory named x.zip! is not an archive');
        $this->assertNull(Archive::split($this->src . '/plain'));
    }

    // --- browse ----------------------------------------------------------

    /** @return array<string,array{0:string,1:string}> */
    public static function formats(): array
    {
        return ['zip' => ['zip', 'a.zip'], 'tar' => ['tar', 'a.tar'], 'tgz' => ['tgz', 'a.tar.gz'], 'tbz' => ['tbz', 'a.tar.bz2']];
    }

    /** @dataProvider formats */
    public function testEnterBrowsesEveryFormatAsAReadOnlyDirectory(string $kind, string $file): void
    {
        $path = $this->src . '/' . $file;
        if ($kind === 'zip') {
            $this->makeZip($path, ['readme.txt' => "hello\n", 'docs/guide.md' => '# guide', 'docs/deep/leaf.txt' => 'leaf']);
        } else {
            $this->makeTar($path, $this->sampleRecords(), $kind);
        }
        $m = $this->open($this->mgr(), $file);
        $this->assertSame($path . '!', $m->activePane()->cwd);
        $this->assertSame(['..', 'docs', 'readme.txt'], $this->names($m));
        $this->assertStringContainsString('read-only archive view', $m->status);
        $docs = $m->activePane()->entries[1];
        $this->assertTrue($docs->isDir, 'implicit directory derived from its children');
        $readme = $m->activePane()->entries[2];
        $this->assertSame(6, $readme->size);
        $m = $this->open($m, 'docs');
        $this->assertSame($path . '!/docs', $m->activePane()->cwd);
        $this->assertSame(['..', 'deep', 'guide.md'], $this->names($m));
        $m = $this->open($m, 'deep');
        $this->assertSame(['..', 'leaf.txt'], $this->names($m));
    }

    public function testGoUpWalksBackOutAndParksOnTheArchiveFile(): void
    {
        $this->makeTar($this->src . '/a.tar', $this->sampleRecords());
        $m = $this->open($this->open($this->mgr(), 'a.tar'), 'docs');
        $m = $this->key($m, KeyType::Left);
        $this->assertSame($this->src . '/a.tar!', $m->activePane()->cwd);
        $m = $this->key($m, KeyType::Left);
        $this->assertSame($this->src, $m->activePane()->cwd);
        $this->assertSame('a.tar', $m->activePane()->currentEntry()?->name);
    }

    public function testLOnAnArchiveBrowsesToo(): void
    {
        $this->makeZip($this->src . '/a.zip', ['f.txt' => 'x']);
        $m = $this->press($this->cursorOn($this->mgr(), 'a.zip'), 'l');
        $this->assertSame($this->src . '/a.zip!', $m->activePane()->cwd);
    }

    public function testTypedPathDoorOpensInsideAnArchive(): void
    {
        $this->makeTar($this->src . '/a.tar', $this->sampleRecords());
        $m = $this->press($this->mgr(), 'o', ctrl: true);
        foreach (str_split($this->src . '/a.tar!/docs') as $ch) {
            $m = $this->press($m, $ch);
        }
        $m = $this->key($m, KeyType::Enter);
        $this->assertSame($this->src . '/a.tar!/docs', $m->activePane()->cwd);
        $this->assertSame(['..', 'deep', 'guide.md'], $this->names($m));
    }

    public function testCorruptArchiveIsRefusedAndThePaneStays(): void
    {
        file_put_contents($this->src . '/bad.zip', 'this is not a zip');
        file_put_contents($this->src . '/bad.tar', str_repeat('x', 1024));
        foreach (['bad.zip', 'bad.tar'] as $f) {
            $m = $this->open($this->mgr(), $f);
            $this->assertSame($this->src, $m->activePane()->cwd, $f);
            $this->assertStringContainsString('cannot open archive ' . $f, $m->status);
        }
    }

    public function testEnterOnAFileInsideTheArchiveRefusesWithoutSpawning(): void
    {
        $this->makeZip($this->src . '/a.zip', ['f.txt' => 'x']);
        $m = $this->open($this->mgr(), 'a.zip');
        [$m, $cmd] = $this->cursorOn($m, 'f.txt')->update(new KeyMsg(KeyType::Enter));
        $this->assertNull($cmd);
        $this->assertStringContainsString('x extracts', $m->status);
    }

    // --- safety on the way in -------------------------------------------

    public function testUnsafeAndLinkRecordsAreHiddenAndCounted(): void
    {
        $this->makeTar($this->src . '/evil.tar', [
            ['name' => 'ok.txt', 'body' => 'ok'],
            ['name' => '../escape.txt', 'body' => 'no'],
            ['name' => '/abs.txt', 'body' => 'no'],
            ['name' => 'a/../../up.txt', 'body' => 'no'],
            ['name' => 'link', 'type' => '2', 'link' => '/etc/passwd'],
            ['name' => 'hard', 'type' => '1', 'link' => 'ok.txt'],
            ['name' => 'dev', 'type' => '3'],
        ]);
        $m = $this->open($this->mgr(), 'evil.tar');
        $this->assertSame(['..', 'ok.txt'], $this->names($m));
        $this->assertStringContainsString('6 link/special/unsafe entries hidden', $m->status);
    }

    public function testZipSymlinkAndSlipNamesAreHidden(): void
    {
        $z = new \ZipArchive();
        $z->open($this->src . '/evil.zip', \ZipArchive::CREATE);
        $z->addFromString('ok.txt', 'ok');
        $z->addFromString('../slip.txt', 'no');
        $z->addFromString('lnk', '/etc/passwd');
        $z->setExternalAttributesName('lnk', \ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $z->close();
        $m = $this->open($this->mgr(), 'evil.zip');
        $this->assertSame(['..', 'ok.txt'], $this->names($m));
        $this->assertStringContainsString('2 link/special/unsafe', $m->status);
    }

    public function testTooManyEntriesRefusesTheWholeArchive(): void
    {
        Archive::useLimits(null, null, 10);
        $recs = [];
        for ($i = 0; $i < 11; $i++) {
            $recs[] = ['name' => 'f' . $i];
        }
        $this->makeTar($this->src . '/big.tar', $recs);
        $m = $this->open($this->mgr(), 'big.tar');
        $this->assertSame($this->src, $m->activePane()->cwd);
        $this->assertStringContainsString('cannot open archive', $m->status);
    }

    public function testCompressedScanLimitStopsABombWhileIndexing(): void
    {
        $this->makeTar($this->src . '/bomb.tar.gz', [['name' => 'big.bin', 'body' => str_repeat('A', 200000)]], 'tgz');
        Archive::useLimits(50000, null);
        $m = $this->open($this->mgr(), 'bomb.tar.gz');
        $this->assertSame($this->src, $m->activePane()->cwd);
        $this->assertStringContainsString('cannot open archive', $m->status);
    }

    // --- read-only guard -------------------------------------------------

    public function testMutatingChordsAreRefusedInsideAnArchive(): void
    {
        $this->makeZip($this->src . '/a.zip', ['f.txt' => 'x']);
        $in = $this->cursorOn($this->open($this->mgr(), 'a.zip'), 'f.txt');
        foreach (['d', 'c', 'm', 'R', 'N', 'y', 'Y', 'p'] as $chord) {
            $after = $this->press($in, $chord);
            $this->assertSame(ConfirmState::None, $after->confirm, $chord);
            $this->assertStringContainsString('read-only', $after->status, $chord);
            $this->assertNull($after->yank, $chord);
        }
    }

    public function testCopyAndMoveIntoAnArchivePaneAreRefusedToo(): void
    {
        $this->makeZip($this->dst . '/a.zip', ['f.txt' => 'x']);
        file_put_contents($this->src . '/real.txt', 'r');
        $m = Manager::start($this->src, $this->dst . '/a.zip!', \SugarCraft\Files\FsLister::lister());
        $m = $this->cursorOn($m, 'real.txt');
        foreach (['c', 'm'] as $chord) {
            $after = $this->press($m, $chord);
            $this->assertSame(ConfirmState::None, $after->confirm, $chord);
            $this->assertStringContainsString('read-only', $after->status);
        }
    }

    public function testBrowsingKeysStillWorkInsideAnArchive(): void
    {
        $this->makeZip($this->src . '/a.zip', ['f.txt' => 'x', 'g.txt' => 'y']);
        $m = $this->open($this->mgr(), 'a.zip');
        $m = $this->press($m, 'j');
        $this->assertSame(1, $m->activePane()->cursor);
        [$m] = $m->update(new KeyMsg(KeyType::Space, ' '));
        $this->assertNotSame([], $m->activePane()->selected);
        $this->assertNotNull($this->press($m, 'I') ? true : null);
    }

    // --- extract ---------------------------------------------------------

    private function stagingDirs(): array
    {
        return glob(rtrim(sys_get_temp_dir(), '/') . '/candyfiles-x-*') ?: [];
    }

    public function testXExtractsAFileThroughTheConfirmGate(): void
    {
        $this->makeZip($this->src . '/a.zip', ['readme.txt' => "hello\n", 'docs/guide.md' => '# guide']);
        $before = $this->stagingDirs();
        $m = $this->cursorOn($this->open($this->mgr(), 'a.zip'), 'readme.txt');
        $m = $this->press($m, 'x');
        $this->assertSame(ConfirmState::CopySelected, $m->confirm);
        $this->assertStringContainsString("extract 'readme.txt' to " . $this->dst, $m->status);
        $this->assertFileDoesNotExist($this->dst . '/readme.txt', 'nothing lands before the answer');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertStringContainsString('extracting 1 entries', $m->status);
        $m = $this->settle($m, $cmd);
        $this->assertSame("hello\n", file_get_contents($this->dst . '/readme.txt'));
        $this->assertStringContainsString('copied 1 entries', $m->status);
        $this->assertSame($before, $this->stagingDirs(), 'staging tree removed');
        $this->assertSame(0, $m->copyBatchesInFlight);
    }

    /** @dataProvider formats */
    public function testXExtractsADirectoryRecursivelyFromEveryFormat(string $kind, string $file): void
    {
        $path = $this->src . '/' . $file;
        if ($kind === 'zip') {
            $this->makeZip($path, ['readme.txt' => 'r', 'docs/guide.md' => '# guide', 'docs/deep/leaf.txt' => 'leaf']);
        } else {
            $this->makeTar($path, $this->sampleRecords(), $kind);
        }
        $m = $this->cursorOn($this->open($this->mgr(), $file), 'docs');
        $m = $this->press($m, 'x');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->settle($m, $cmd);
        $this->assertSame('# guide', file_get_contents($this->dst . '/docs/guide.md'));
        $this->assertSame('leaf', file_get_contents($this->dst . '/docs/deep/leaf.txt'));
        $this->assertFileDoesNotExist($this->dst . '/readme.txt', 'unselected entries stay in the archive');
    }

    public function testExtractFromASubdirectoryUsesTheInnerPrefix(): void
    {
        $this->makeTar($this->src . '/a.tar', $this->sampleRecords());
        $m = $this->cursorOn($this->open($this->open($this->mgr(), 'a.tar'), 'docs'), 'guide.md');
        $m = $this->press($m, 'x');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->settle($m, $cmd);
        $this->assertSame('# guide', file_get_contents($this->dst . '/guide.md'));
    }

    public function testExtractAnnouncesOverwritesLikeCopy(): void
    {
        $this->makeZip($this->src . '/a.zip', ['readme.txt' => 'new']);
        file_put_contents($this->dst . '/readme.txt', 'old');
        $m = $this->cursorOn($this->open($this->mgr(), 'a.zip'), 'readme.txt');
        [$m, $cmd] = $this->press($m, 'x')->update(new KeyMsg(KeyType::Char, 'y'));
        $m = $this->settle($m, $cmd);
        $this->assertSame('new', file_get_contents($this->dst . '/readme.txt'));
        $this->assertStringContainsString('1 overwritten', $m->status);
    }

    public function testDecliningTheExtractTouchesNothing(): void
    {
        $this->makeZip($this->src . '/a.zip', ['readme.txt' => 'r']);
        $m = $this->press($this->cursorOn($this->open($this->mgr(), 'a.zip'), 'readme.txt'), 'x');
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame('cancelled', $m->status);
        $this->assertFileDoesNotExist($this->dst . '/readme.txt');
    }

    public function testExtractIntoAnArchivePaneIsRefused(): void
    {
        $this->makeZip($this->src . '/a.zip', ['readme.txt' => 'r']);
        $this->makeZip($this->dst . '/b.zip', ['x.txt' => 'x']);
        $m = Manager::start($this->src . '/a.zip!', $this->dst . '/b.zip!', \SugarCraft\Files\FsLister::lister());
        $m = $this->press($this->cursorOn($m, 'readme.txt'), 'x');
        $this->assertSame(ConfirmState::None, $m->confirm);
        $this->assertStringContainsString('read-only', $m->status);
    }

    public function testZipSlipEntriesNeverReachTheFilesystemOnExtractAll(): void
    {
        $this->makeTar($this->src . '/evil.tar', [
            ['name' => 'ok.txt', 'body' => 'ok'],
            ['name' => '../escape.txt', 'body' => 'NO'],
            ['name' => 'sub/../../escape2.txt', 'body' => 'NO'],
            ['name' => 'link', 'type' => '2', 'link' => '/etc'],
            ['name' => 'link/passwd', 'body' => 'NO'],
        ]);
        $m = $this->open($this->mgr(), 'evil.tar');
        $m = $this->press($m, 'v');                                  // select all listed
        $m = $this->press($m, 'x');
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Char, 'y'));
        $this->settle($m, $cmd);
        $this->assertSame('ok', file_get_contents($this->dst . '/ok.txt'));
        foreach ([$this->tmp . '/escape.txt', $this->tmp . '/escape2.txt', $this->dst . '/escape.txt'] as $leak) {
            $this->assertFileDoesNotExist($leak);
            $this->assertFalse(is_link($leak), $leak);
        }
        // The symlink record was never materialised: 'link' is a plain
        // directory holding the (safe-named) file the archive also carried.
        $this->assertFalse(is_link($this->dst . '/link'));
        $this->assertSame('NO', file_get_contents($this->dst . '/link/passwd'));
        $this->assertDirectoryDoesNotExist('/etc/candyfiles-probe');
    }

    public function testExtractionBombCapRejectsAndCleansStaging(): void
    {
        $this->makeTar($this->src . '/bomb.tar', [['name' => 'big.bin', 'body' => str_repeat('A', 300000)]]);
        $before = $this->stagingDirs();
        $m = $this->cursorOn($this->open($this->mgr(), 'bomb.tar'), 'big.bin');
        Archive::useLimits(null, 100000);
        [$m, $cmd] = $this->press($m, 'x')->update(new KeyMsg(KeyType::Char, 'y'));
        $m = $this->settle($m, $cmd);
        $this->assertFileDoesNotExist($this->dst . '/big.bin');
        $this->assertStringContainsString('with 1 errors', $m->status);
        $this->assertStringContainsString('decompression size limit', $m->status);
        $this->assertSame($before, $this->stagingDirs());
        $this->assertSame(0, $m->copyBatchesInFlight);
    }

    public function testExtractedFilesDropArchiveModes(): void
    {
        $this->makeTar($this->src . '/a.tar', [['name' => 'x.sh', 'body' => '#!/bin/sh']]);
        $m = $this->cursorOn($this->open($this->mgr(), 'a.tar'), 'x.sh');
        [$m, $cmd] = $this->press($m, 'x')->update(new KeyMsg(KeyType::Char, 'y'));
        $this->settle($m, $cmd);
        $this->assertSame(0, fileperms($this->dst . '/x.sh') & 04111, 'no setuid/exec bits from the archive');
    }

    public function testXOutsideAnArchiveStillRunsExecutables(): void
    {
        file_put_contents($this->src . '/plain.txt', 'x');
        $m = $this->press($this->cursorOn($this->mgr(), 'plain.txt'), 'x');
        $this->assertStringContainsString('not executable', $m->status);
    }
}

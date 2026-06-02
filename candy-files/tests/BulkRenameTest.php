<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Files\BulkRename;

final class BulkRenameTest extends TestCase
{
    public function testFilesEmptyByDefault(): void
    {
        $br = BulkRename::files([]);
        $this->assertSame([], $br->files);
    }

    public function testWithPattern(): void
    {
        $br = BulkRename::files(['file1.txt', 'file2.txt'])
            ->withPattern('/\.txt$/');
        $this->assertSame('/\.txt$/', $br->pattern);
    }

    public function testWithTemplate(): void
    {
        $br = BulkRename::files(['file1.txt'])
            ->withTemplate('new_{name}.{ext}');
        $this->assertSame('new_{name}.{ext}', $br->template);
    }

    public function testWithStartNum(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt'])
            ->withPattern('/(.+)\.txt$/')
            ->withTemplate('{name}_{n}')
            ->withStartNum(10)
            ->withPadNum(3);
        $this->assertSame(10, $br->startNum);
        $this->assertSame(3, $br->padNum);
    }

    public function testWithStepNum(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt'])
            ->withStepNum(5);
        $this->assertSame(5, $br->stepNum);
    }

    public function testHasValidPatternReturnsFalseForEmptyPattern(): void
    {
        $br = BulkRename::files(['a.txt']);
        $this->assertFalse($br->hasValidPattern());
    }

    public function testHasValidPatternReturnsFalseForInvalidRegex(): void
    {
        $br = BulkRename::files(['a.txt'])->withPattern('[invalid');
        $this->assertFalse($br->hasValidPattern());
    }

    public function testHasValidPatternReturnsTrueForValidRegex(): void
    {
        $br = BulkRename::files(['a.txt'])->withPattern('/\.txt$/');
        $this->assertTrue($br->hasValidPattern());
    }

    public function testPreviewEmptyWhenNoPattern(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt']);
        $this->assertSame([], $br->preview());
    }

    public function testPreviewEmptyWhenFilesEmpty(): void
    {
        $br = BulkRename::files([])
            ->withPattern('/.+/')
            ->withTemplate('x');
        $this->assertSame([], $br->preview());
    }

    public function testPreviewWithSequentialNumbering(): void
    {
        $br = BulkRename::files(['photo1.jpg', 'photo2.jpg', 'photo3.jpg'])
            ->withPattern('/(.+)\.(\w+)$/')
            ->withTemplate('{name}_copy.{ext}')
            ->withStartNum(1)
            ->withPadNum(1);
        $preview = $br->preview();
        $this->assertCount(3, $preview);
        $this->assertSame('photo1.jpg', $preview[0]['original']);
        $this->assertSame('photo1_copy.jpg', $preview[0]['renamed']);
        $this->assertSame('photo2.jpg', $preview[1]['original']);
        $this->assertSame('photo2_copy.jpg', $preview[1]['renamed']);
    }

    public function testPreviewWithNPadded(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt'])
            ->withPattern('/(.+)\.txt$/')
            ->withTemplate('{name}_{n}.txt')
            ->withStartNum(1)
            ->withStepNum(2)
            ->withPadNum(3);
        $preview = $br->preview();
        $this->assertSame('a_001.txt', $preview[0]['renamed']);
        $this->assertSame('b_003.txt', $preview[1]['renamed']);
    }

    public function testRenamedReturnsListOfNewNames(): void
    {
        $br = BulkRename::files(['file1.txt', 'file2.txt'])
            ->withPattern('/^(.+)\.txt$/')
            ->withTemplate('new_$1.txt');
        $renamed = $br->renamed();
        $this->assertCount(2, $renamed);
        $this->assertSame('new_file1.txt', $renamed[0]);
        $this->assertSame('new_file2.txt', $renamed[1]);
    }

    public function testWillChangeReturnsFalseForSameName(): void
    {
        $br = BulkRename::files(['a.txt'])
            ->withPattern('/(.+)/')
            ->withTemplate('$1');  // Identity replacement via capture group
        $this->assertFalse($br->willChange('a.txt'));
    }

    public function testWillChangeReturnsTrueForDifferentName(): void
    {
        $br = BulkRename::files(['a.txt'])
            ->withPattern('/\.txt$/')
            ->withTemplate('renamed.txt');
        $this->assertTrue($br->willChange('a.txt'));
    }

    public function testWillChangeReturnsFalseForEmptyPattern(): void
    {
        $br = BulkRename::files(['a.txt']);
        $this->assertFalse($br->willChange('a.txt'));
    }

    public function testIsValidReturnsFalseWhenNoPattern(): void
    {
        $br = BulkRename::files(['a.txt']);
        $this->assertFalse($br->isValid());
    }

    public function testIsValidReturnsFalseWhenDuplicateResults(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt'])
            ->withPattern('/.+/')
            ->withTemplate('same.txt');  // All map to same name
        $this->assertFalse($br->isValid());
    }

    public function testIsValidReturnsTrueWhenNoConflicts(): void
    {
        $br = BulkRename::files(['a.txt', 'b.txt'])
            ->withPattern('/^(.+)\.txt$/')
            ->withTemplate('renamed_$1.txt');
        $this->assertTrue($br->isValid());
    }

    public function testImmutability(): void
    {
        $original = BulkRename::files(['a.txt']);
        $modified = $original->withPattern('/\.txt$/');

        $this->assertNotSame($original, $modified);
        $this->assertSame('', $original->pattern);
        $this->assertSame('/\.txt$/', $modified->pattern);
    }

    public function testFluentChain(): void
    {
        $br = BulkRename::files(['f1.txt', 'f2.txt'])
            ->withPattern('/^(.+)\.(\w+)$/')
            ->withTemplate('{name}_v{n}.{ext}')
            ->withStartNum(1)
            ->withStepNum(1)
            ->withPadNum(2);

        $this->assertSame('/^(.+)\.(\w+)$/', $br->pattern);
        $this->assertSame('{name}_v{n}.{ext}', $br->template);
        $this->assertSame(1, $br->startNum);
        $this->assertSame(1, $br->stepNum);
        $this->assertSame(2, $br->padNum);
        $this->assertCount(2, $br->files);
    }

    public function testExecuteReturnsErrorCountOnRenameFailure(): void
    {
        $br = BulkRename::files(['/nonexistent/a.txt', '/nonexistent/b.txt'])
            ->withPattern('/(.+)\.txt$/')
            ->withTemplate('renamed_$1.txt');

        // Failing renamer always returns false
        $failRenamer = static fn(string $src, string $dst): bool => false;
        $errors = $br->execute($failRenamer, '/nonexistent');
        $this->assertSame(2, $errors);
    }

    public function testExecuteSkipsUnchangedNames(): void
    {
        $renamedFiles = [];
        $br = BulkRename::files(['a.txt'])
            ->withPattern('/^(.+)\.txt$/')
            ->withTemplate('{name}.txt');  // Same name

        $recordRenamer = static function (string $src, string $dst) use (&$renamedFiles): bool {
            $renamedFiles[] = $dst;
            return true;
        };

        $br->execute($recordRenamer, '/tmp');
        // No rename should be called since source === destination
        $this->assertSame([], $renamedFiles);
    }
}

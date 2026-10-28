<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Format;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Format\FormatAutoTable;

/**
 * C-TOOL-5.b: the built-in formatter table behind `formatAuto`. Pins the
 * preference order (ruff before black), the deliberate shape of the prettier
 * entry — the PROJECT's own node_modules binary, never a global one — the
 * command strings the arm will run, and the presence rule that answers
 * separator-bearing names against the given root rather than the host `PATH`
 * memo. The runner-side half (explicit wins, suppression, trust fold) is in
 * {@see FormatRunnerTest}.
 *
 * @see FormatAutoTable
 */
final class FormatAutoTableTest extends TestCase
{
    private string $tableRoot;

    protected function setUp(): void
    {
        $this->tableRoot = sys_get_temp_dir() . '/sc-autotable-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->tableRoot, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (iterator_to_array(new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tableRoot, \FilesystemIterator::SKIP_DOTS),
        ), false) as $leaf) {
            /** @var \SplFileInfo $leaf */
            $leaf->isDir() ? @rmdir($leaf->getPathname()) : @unlink($leaf->getPathname());
        }
        @rmdir($this->tableRoot);
    }

    public function testTheTableKnowsTheDocumentedExtensionsAndNothingElse(): void
    {
        $known = ['go', 'rs', 'py', 'sh', 'bash', 'tf', 'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx',
            'json', 'jsonc', 'css', 'scss', 'less', 'md', 'markdown', 'yaml', 'yml', ];
        $this->assertSame($known, FormatAutoTable::extensions(), 'the documented extension roster moved — docs/HOOKS.md names it');

        $this->assertSame([], FormatAutoTable::candidatesFor('nope'));
        $this->assertSame([], FormatAutoTable::candidatesFor(''));
        $this->assertSame(FormatAutoTable::candidatesFor('GO'), FormatAutoTable::candidatesFor('go'), 'lookup lower-cases, the map does too');
        foreach (FormatAutoTable::extensions() as $extension) {
            $this->assertNotSame([], FormatAutoTable::candidatesFor($extension), $extension);
        }
    }

    public function testCandidatesRideInPreferenceOrder(): void
    {
        $python = FormatAutoTable::candidatesFor('py');
        $this->assertCount(2, $python);
        $this->assertSame('ruff', $python[0]['binary'], 'ruff format is the modern default; black is the fallback');
        $this->assertSame(['format'], $python[0]['args']);
        $this->assertSame('black', $python[1]['binary']);
        $this->assertSame([], $python[1]['args']);

        // Every other entry is a single candidate today.
        foreach (FormatAutoTable::extensions() as $extension) {
            $this->assertCount($extension === 'py' ? 2 : 1, FormatAutoTable::candidatesFor($extension), $extension);
        }
    }

    public function testThePrettierEntryIsTheProjectsOwnBinaryNeverAGlobalOne(): void
    {
        $prettierFamily = ['js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'json', 'jsonc', 'css', 'scss', 'less', 'md', 'markdown', 'yaml', 'yml'];
        foreach ($prettierFamily as $extension) {
            $this->assertSame(
                [['binary' => 'node_modules/.bin/prettier', 'args' => ['--write']]],
                FormatAutoTable::candidatesFor($extension),
                $extension,
            );
        }

        // A global `prettier` anywhere in the table would silently format a
        // repository against settings it never agreed to.
        $allBinaries = [];
        foreach (FormatAutoTable::extensions() as $extension) {
            foreach (FormatAutoTable::candidatesFor($extension) as $candidate) {
                $allBinaries[] = $candidate['binary'];
            }
        }
        $this->assertNotContains('prettier', $allBinaries);
    }

    public function testCommandStringsAreThePlainCommandLineWithoutTheFile(): void
    {
        $this->assertSame('gofmt -w', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('go')[0]));
        $this->assertSame('rustfmt', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('rs')[0]));
        $this->assertSame('ruff format', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('py')[0]));
        $this->assertSame('shfmt -w', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('sh')[0]));
        $this->assertSame('terraform fmt', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('tf')[0]));
        $this->assertSame('node_modules/.bin/prettier --write', FormatAutoTable::commandString(FormatAutoTable::candidatesFor('ts')[0]));
    }

    public function testPresenceAnswersSeparatorNamesAgainstTheGivenRootNotTheHost(): void
    {
        $this->assertFalse(FormatAutoTable::isPresent('node_modules/.bin/prettier', $this->tableRoot), 'a tree without node_modules has no prettier');

        // Plant the project-local binary; a second root must NOT inherit the
        // answer — separator names skip the host-memo family entirely.
        mkdir($this->tableRoot . '/node_modules/.bin', 0o700, true);
        file_put_contents($this->tableRoot . '/node_modules/.bin/prettier', "#!/bin/sh\nexit 0\n");
        chmod($this->tableRoot . '/node_modules/.bin/prettier', 0o755);
        $otherRoot = $this->tableRoot . '-other';
        mkdir($otherRoot, 0o700, true);

        $this->assertTrue(FormatAutoTable::isPresent('node_modules/.bin/prettier', $this->tableRoot));
        $this->assertFalse(FormatAutoTable::isPresent('node_modules/.bin/prettier', $otherRoot));

        // Present but not executable is not a runnable binary.
        file_put_contents($this->tableRoot . '/node_modules/.bin/plain', 'data');
        chmod($this->tableRoot . '/node_modules/.bin/plain', 0o644);
        $this->assertFalse(FormatAutoTable::isPresent('node_modules/.bin/plain', $this->tableRoot));

        @unlink($this->tableRoot . '/node_modules/.bin/plain');
        @rmdir($otherRoot);
    }

    public function testHostNamesAreHostLookupsAndMissingNamesAnswerFalse(): void
    {
        // The negative half is hermetic on any host; the positive shape
        // (`sh` exists wherever PHP's own shell fixtures run) pins the PATH
        // branch answers for real installed names too.
        $this->assertFalse(FormatAutoTable::isPresent('sc-no-such-formatter-here', $this->tableRoot));
        $this->assertTrue(FormatAutoTable::isPresent('sh', $this->tableRoot));
    }
}

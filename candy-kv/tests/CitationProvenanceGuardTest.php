<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;

/**
 * KV-062 guard: a `Mirrors charmbracelet/skate.<verb>` cite is a parity
 * claim, so it lives only where the research table proves skate ships the
 * verb — the CLI `case` sites in bin/candy-kv — and nowhere else.
 */
final class CitationProvenanceGuardTest extends TestCase
{
    private const SKATE_VERBS = ['set', 'get', 'list', 'delete', 'list-dbs', 'delete-db'];

    public function testEverySkateVerbCaseCarriesItsCiteDirectlyAbove(): void
    {
        $bin = (string) file_get_contents(__DIR__ . '/../bin/candy-kv');
        foreach (self::SKATE_VERBS as $verb) {
            $this->assertStringContainsString(
                "    // Mirrors charmbracelet/skate.{$verb}\n    case '{$verb}':\n",
                $bin,
                "verb '{$verb}' lost its cite or the cite drifted off its case",
            );
        }
    }

    public function testNoCiteNamesAVerbOutsideTheEvidenceRoster(): void
    {
        $offenders = [];
        foreach ($this->files() as $file) {
            if (preg_match_all('#charmbracelet/skate\.([A-Za-z-]+)#', (string) file_get_contents($file), $m) > 0) {
                foreach ($m[1] as $verb) {
                    if (!in_array($verb, self::SKATE_VERBS, true)) {
                        $offenders[] = basename($file) . ': ' . $verb;
                    }
                }
            }
        }
        $this->assertSame([], $offenders, 'prune/import/export and library methods are not skate verbs');
    }

    public function testSrcCarriesNoVerbCites(): void
    {
        $offenders = [];
        foreach ($this->files() as $file) {
            if (str_contains($file, '/src/') && preg_match('#charmbracelet/skate\.#', (string) file_get_contents($file)) === 1) {
                $offenders[] = $file;
            }
        }
        $this->assertSame([], $offenders, 'verb cites belong at the CLI sites only');
    }

    /** @return list<string> */
    private function files(): array
    {
        $root = dirname(__DIR__);
        $files = [$root . '/bin/candy-kv'];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }
}

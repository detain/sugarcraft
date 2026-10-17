<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCraft\Code\Agents\ForeignAgentPresetRegistry;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * ISS-088: the foreign importer's per-file verdicts — files it SKIPPED and
 * imports it took with LOSSES — used to die in `error_log`, invisible to the
 * operator reading the transcript. Now {@see ForeignAgentPresetRegistry::skippedFiles()}
 * exists beside {@see ForeignAgentPresetRegistry::warnings()}, and
 * {@see Bootstrap::foreignAgentPresets()} drains BOTH into the launch
 * collector: skips as "skipped N foreign agent file(s): path (why)", losses as
 * "imported N foreign agent file(s) with changes: why". The two outcomes keep
 * their own envelopes — a shorter roster and a degraded roster are different
 * facts — but they rise as ONE seam call, mirroring how the native sibling
 * seats its skip and shadow rows.
 *
 * @see Bootstrap::FOREIGN_PRESET_SKIP_NOTICE_FORMAT
 * @see Bootstrap::FOREIGN_PRESET_CHANGE_NOTICE_FORMAT
 */
final class ForeignAgentPresetNoticeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy_code_foreign_notice_' . uniqid('', true);
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testTheRegistryRecordsASkippedForeignFileBesideTheGoodOnes(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeForeignAgent($agents, 'good', "description: fine import");
        $bad = $this->writeRaw($agents, 'broken', "# nobody closed a frontmatter fence here\n");

        $this->withSandboxedHome(function () use ($bad, $repo): void {
            $registry = new ForeignAgentPresetRegistry();
            $presets = $registry->discover($repo);

            $this->assertSame(['good'], array_keys($presets), 'the good file still imports (the sandboxed HOME has no foreign tier to pollute)');
            $this->assertSame([$bad], array_keys($registry->skippedFiles()));
            $this->assertStringContainsString('No YAML frontmatter found', $registry->skippedFiles()[$bad]);
        });
    }

    /** Recomputed per walk, so a skip never outlives the file that caused it. */
    public function testSkippedFilesIsRecomputedByEachDiscover(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeRaw($agents, 'broken', "no frontmatter\n");

        $this->withSandboxedHome(function () use ($agents, $repo): void {
            $registry = new ForeignAgentPresetRegistry();
            $registry->discover($repo);
            $this->assertCount(1, $registry->skippedFiles());

            $this->writeForeignAgent($agents, 'broken', "description: now it parses");
            $registry->discover($repo);
            $this->assertSame([], $registry->skippedFiles());
        });
    }

    public function testALaunchNoticesSkippedForeignFilesByPath(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeForeignAgent($agents, 'good', "description: fine import");
        $bad = $this->writeRaw($agents, 'broken', "no frontmatter at all\n");

        $this->withSandboxedHome(function () use ($repo, $bad): void {
            $presets = null;
            $notices = $this->noticesRaisedBy(function () use ($repo, &$presets): void {
                $presets = Bootstrap::foreignAgentPresets($repo);
            });
            $this->assertSame(['good'], array_keys($presets));

            $this->assertStringContainsString(
                // The path rides the row once: the drain strips the parser's
                // trailing " in: {file}" because the envelope already names it.
                sprintf(Bootstrap::FOREIGN_PRESET_SKIP_NOTICE_FORMAT, 1, '', $bad . ' (No YAML frontmatter found)'),
                $notices,
                'the vanished file is reported by path with the parse error beside it',
            );
        });
    }

    public function testALaunchNoticesLossyImportsWithTheirOwnEnvelope(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeForeignAgent($agents, 'inl', "description: Inline servers\nmcpServers:\n  filesystem:\n    command: npx");

        $this->withSandboxedHome(function () use ($repo): void {
            $presets = null;
            $notices = $this->noticesRaisedBy(function () use ($repo, &$presets): void {
                $presets = Bootstrap::foreignAgentPresets($repo);
            });
            $this->assertSame(['filesystem'], $presets['inl']->mcpServers, 'the name survives the loss');

            $this->assertStringContainsString('imported 1 foreign agent file with changes: ', $notices);
            $this->assertStringContainsString('mcpServers.filesystem', $notices);
            $this->assertStringNotContainsString('skipped 1 foreign agent file', $notices, 'a degraded import is not a vanished one, and an earlier window is not this walk either');
        });
    }

    /**
     * BOTH OUTCOMES in one walk: two sentences, ONE seam call, skip first —
     * the roster shrank AND what remains carries less than its author wrote.
     */
    public function testSkipsAndLossesRiseAsASingleMergedNoticeRow(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeRaw($agents, 'broken', "no frontmatter\n");
        $this->writeForeignAgent($agents, 'inl', "description: Inline servers\nmcpServers:\n  filesystem:\n    command: npx");

        $this->withSandboxedHome(function () use ($repo): void {
            $added = $this->noticeRowsRaisedBy(fn (): array => Bootstrap::foreignAgentPresets($repo));

            $this->assertCount(1, $added, 'the seam takes one call, the way agentPresets seats its rows');
            $this->assertStringContainsString('skipped 1 foreign agent file: ', $added[0]);
            // The row is whole, not clipped: with the path de-duplicated the
            // honest pair fits LAUNCH_NOTICE_MAX_CHARS, and clip-bound
            // arithmetic stays out of this pin.
            $this->assertStringContainsString(' imported 1 foreign agent file with changes: ', $added[0], 'the two sentences seat on one row');
            $this->assertStringNotContainsString('clipped', $added[0]);
        });
    }

    /** A clean import is SILENT — the drain raises nothing when both rosters are empty. */
    public function testACleanForeignImportRaisesNoNotice(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        $this->writeForeignAgent($agents, 'good', "description: fine import");

        $this->withSandboxedHome(function () use ($repo): void {
            $added = $this->noticeRowsRaisedBy(fn (): array => Bootstrap::foreignAgentPresets($repo));

            $this->assertSame([], $added, 'silence about nothing-to-report is still silence');
        });
    }

    /**
     * ISS-090(d): THIRTEEN VANISHED FILES ARE REPORTED AS THIRTEEN, NOT AS A
     * WALL. The envelope counts everything; the listing draws at most ten rows
     * and names the fold. The three hidden names are the discriminator that
     * survives the byte clip: without the row cap they would sit inside the
     * kept tail window, and with it they exist nowhere in the row.
     */
    public function testTheSkipNoticeListsTenRowsAndNamesTheRestByCount(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        for ($i = 1; $i <= 13; ++$i) {
            $this->writeRaw($agents, sprintf('f%02d', $i), "no frontmatter\n");
        }

        $this->withSandboxedHome(function () use ($repo): void {
            $notices = $this->noticesRaisedBy(function () use ($repo): void {
                Bootstrap::foreignAgentPresets($repo);
            });

            $this->assertStringContainsString('skipped 13 foreign agent files: ', $notices, 'the envelope counts every file, not every row');
            $this->assertStringContainsString('(and 3 more)', $notices, 'the fold must be named, not silent');
            $this->assertStringContainsString('f01.md', $notices);
            foreach (['f11.md', 'f12.md', 'f13.md'] as $folded) {
                $this->assertStringNotContainsString($folded, $notices, "row {$folded} belongs to the count, not to the listing");
            }
        });
    }

    /** Same cap, same honest tail, on the changes envelope. */
    public function testTheChangeNoticeListsTenRowsAndNamesTheRestByCount(): void
    {
        $repo = $this->tempDir . '/repo';
        $agents = $repo . '/.claude/agents';
        mkdir($agents, 0755, true);
        for ($i = 1; $i <= 12; ++$i) {
            $this->writeForeignAgent(
                $agents,
                sprintf('w%02d', $i),
                "description: Lossy {$i}\nmcpServers:\n  sv" . sprintf('%02d', $i) . ":\n    command: npx",
            );
        }

        $this->withSandboxedHome(function () use ($repo): void {
            $notices = $this->noticesRaisedBy(function () use ($repo): void {
                Bootstrap::foreignAgentPresets($repo);
            });

            $this->assertStringContainsString('imported 12 foreign agent files with changes: ', $notices);
            $this->assertStringContainsString('(and 2 more)', $notices);
            foreach (['sv11', 'sv12'] as $folded) {
                $this->assertStringNotContainsString($folded, $notices, "warning {$folded} belongs to the count, not to the listing");
            }
        });
    }

    /**
     * @param callable():void $body
     */
    private function withSandboxedHome(callable $body): void
    {
        $home = $this->tempDir . '/home';
        mkdir($home, 0700, true);
        $originalHome = getenv('HOME') ?: '';
        $originalServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home;

        try {
            // The fixture home declares no foreign agents, so whatever rows
            // the launch raises can only come from the project tier planted
            // by the caller.
            $body();
        } finally {
            if ($originalHome !== '') {
                putenv('HOME=' . $originalHome);
            } else {
                putenv('HOME');
            }

            if ($originalServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $originalServerHome;
            }
        }
    }

    /**
     * Run a launch-path call and return ONLY the notice rows it added to the
     * process collector — earlier windows' rows are other tests' evidence,
     * never this walk's.
     *
     * @param callable():mixed $run
     */
    private function noticeRowsRaisedBy(callable $run): array
    {
        $before = \count($this->currentLaunchNotices());
        $run();

        return \array_slice($this->currentLaunchNotices(), $before);
    }

    /**
     * @param callable():mixed $run
     */
    private function noticesRaisedBy(callable $run): string
    {
        return implode("\n", $this->noticeRowsRaisedBy($run));
    }

    /**
     * The raw collector list, read past the accessor so the overflow tail row
     * {@see Bootstrap::launchNotices()} synthesises cannot count as an entry.
     *
     * @return list<string>
     */
    private function currentLaunchNotices(): array
    {
        $property = new ReflectionProperty(Bootstrap::class, 'launchNotices');

        /** @var list<string> $notices */
        $notices = $property->getValue();

        return $notices;
    }

    private function writeForeignAgent(string $dir, string $name, string $frontmatter): string
    {
        return $this->writeRaw($dir, $name, "---\n{$frontmatter}\n---\nBody of {$name}.\n");
    }

    private function writeRaw(string $dir, string $name, string $contents): string
    {
        $file = $dir . '/' . $name . '.md';
        file_put_contents($file, $contents);

        return $file;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

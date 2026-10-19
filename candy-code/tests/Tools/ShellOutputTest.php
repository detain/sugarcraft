<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Sessions\BackgroundShellRegistry;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\ShellOutput;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;

/**
 * SHL-1: the read half of the background shell channel. The registry owns
 * cursor arithmetic (pinned in tests/Sessions); what is pinned HERE is the
 * tool's door (id validation, missing registry) and the PAINT — the exact
 * sentences the model reads, including the honest state wording and the
 * cursor it is told to pass back.
 */
final class ShellOutputTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-out-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->root . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    private function registry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('w2d-test', $this->root);
    }

    /** The house construction seam: the catalog builds these tools. */
    private function tool(?BackgroundShellRegistry $registry = null): ShellOutput
    {
        $skills = new SkillRegistry();

        return ShellOutput::fromCatalog(new ToolBuildContext(
            root: $this->root,
            loader: new InstructionFileLoader($this->root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    private function settle(BackgroundShellRegistry $registry, string $id): void
    {
        for ($i = 0; $i < 160; $i++) {
            if (($registry->status($id)['state'] ?? '') === 'finished') {
                return;
            }
            usleep(25_000);
        }
        self::fail('shell ' . $id . ' never settled');
    }

    public function testAMalformedShellIdIsRefusedWhenReading(): void
    {
        $result = $this->tool()->execute(['shell_id' => 'bash-1']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-xxxxxxxx', $result->content());
    }

    public function testAToolWithoutARegistryRefusesLoudly(): void
    {
        $result = ShellOutput::new()->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell registry is attached', $result->content());
    }

    public function testAnUnregisteredIdIsRefusedByName(): void
    {
        $tool = $this->tool($this->registry());
        $result = $tool->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-deadbeef', $result->content());
        self::assertStringContainsString('is registered by this launch', $result->content());
    }

    public function testThePaintCarriesTheStateLineTheStreamsAndTheNextCursor(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2; exit 3', 'paint');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'max_bytes' => 4]);
        self::assertFalse($result->isError());
        $text = $result->content();
        self::assertStringStartsWith('shell ' . $id . ' — finished (exit 3)', $text);
        // An explicit window is honoured byte-for-byte: stdout fills it, the
        // cursor lands at 4, and nothing is silently destroyed — the next
        // step from 4 collects stderr (the walk never skips inside a window).
        self::assertStringContainsString('AAAA', $text);
        self::assertStringContainsString('[cursor: 4]', $text);

        $next = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => 4, 'max_bytes' => 4]);
        self::assertFalse($next->isError());
        self::assertStringContainsString('[stderr]', $next->content());
        self::assertStringContainsString('BBBB', $next->content());
        self::assertStringContainsString('[cursor: 8]', $next->content());
    }

    public function testAQuietWindowPaintsTheEmptyNoticeInsteadOfNothing(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf hello', 'quiet');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id, 'cursor' => 5]);
        self::assertFalse($result->isError());
        self::assertStringContainsString('(no new output)', $result->content());
    }

    public function testATailSkipIsAdmittedInProse(): void
    {
        $registry = $this->registry();
        $blob = str_repeat('OLDOLDNEWNEW', 6000); // 72,000 bytes, past the default budget
        $started = $registry->start('printf ' . escapeshellarg($blob), 'big');
        $id = (string) $started['shell']['id'];
        $this->settle($registry, $id);

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        // The state-line head is searched, not the whole 64 KB answer: this
        // box's PHP 8.3.6 zend_mem_memmem fast path mis-answers on haystacks
        // this large (needle bytes proven correct by hex dump; the same needle
        // against a short haystack matches). Head-first is also where the note
        // structurally lives — paint puts it on the state line.
        $head = substr($result->content(), 0, 200);
        self::assertStringContainsString('older bytes skipped', $head);
        self::assertStringContainsString('(72000 unread bytes were available)', $head);
        self::assertSame('[cursor: 72000]', substr(trim($result->content()), -15));
    }

    public function testTheRunStateIsNarratedHonestlyMidFlight(): void
    {
        $registry = $this->registry();
        $started = $registry->start('sleep 30', 'live');
        $id = (string) $started['shell']['id'];

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        self::assertStringStartsWith('shell ' . $id . ' — running', $result->content());

        $registry->teardown();
        self::assertNotSame('running', $registry->status($id)['state']);
    }

    public function testTheExportCarriesTheRegistryRowsUnderTheSessionKey(): void
    {
        $registry = $this->registry();
        $tool = $this->tool($registry);
        $started = $registry->start('true', 'export');
        $id = (string) $started['shell']['id'];

        $export = $tool->exportSessionState();
        self::assertArrayHasKey(BackgroundShellRegistry::SESSION_STATE_KEY, $export);
        self::assertArrayHasKey($id, $export[BackgroundShellRegistry::SESSION_STATE_KEY]);

        // Round trip through a sibling instance wired to the same registry
        // (the fork-boundary contract): an empty-table fresh registry
        // recovers the row verbatim.
        $fresh = $this->tool(BackgroundShellRegistry::forSession('w2d-test', $this->root));
        $fresh->mergeSessionState($export);
        $again = $fresh->exportSessionState();
        self::assertSame([$id], array_keys($again[BackgroundShellRegistry::SESSION_STATE_KEY]));

        // Malformed payloads are ignored, never fatal.
        $fresh->mergeSessionState(['backgroundShells' => 'scalar']);
        $fresh->mergeSessionState([]);
        self::assertCount(1, $fresh->exportSessionState()[BackgroundShellRegistry::SESSION_STATE_KEY]);
    }
}

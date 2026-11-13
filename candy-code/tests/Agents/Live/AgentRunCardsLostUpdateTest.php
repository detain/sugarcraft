<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents\Live;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentRunCards;

/**
 * candy-code-agents-004 / S-A03: pins the lost-update law of the card's
 * read-modify-write — concurrent updateRun() calls each land, none overwrites
 * another's change.
 */
final class AgentRunCardsLostUpdateTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/cc-cards-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->root);
    }

    public function testConcurrentUpdatesAreNotLost(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required');
        }
        self::assertTrue(AgentRunCards::recordRun('s1', ['runId' => 'r1', 'parent' => 'p', 'inboxSession' => 'i'], $this->root));

        $n = 8;
        $pids = [];
        for ($i = 0; $i < $n; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                AgentRunCards::updateRun('s1', 'r1', static function (array $c) use ($i): array {
                    usleep(2000);
                    $c['followups'][] = ['from' => 'w' . $i, 'text' => 'hi ' . $i, 'ts' => $i];

                    return $c;
                }, $this->root);
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $runs = AgentRunCards::runs('s1', $this->root);
        self::assertCount(1, $runs);
        self::assertCount($n, $runs[0]['followups']);
    }

    public function testUpdateOfMissingCardIsNull(): void
    {
        self::assertNull(AgentRunCards::updateRun('s1', 'nope', static fn (array $c): array => $c, $this->root));
    }

    public function testCardIsPublishedPrivateThroughTheCanonicalMutate(): void
    {
        self::assertTrue(AgentRunCards::recordRun('s1', ['runId' => 'r1', 'parent' => 'p', 'inboxSession' => 'i'], $this->root));
        $card = $this->root . '/s1/r1/card.json';
        self::assertSame(0o600, fileperms($card) & 0o777);
        self::assertSame('r1', json_decode((string) file_get_contents($card), true)['runId']);
    }

    public function testCorruptCardIsNotRevivedByUpdate(): void
    {
        AgentRunCards::recordRun('s1', ['runId' => 'r1', 'parent' => 'p', 'inboxSession' => 'i'], $this->root);
        file_put_contents($this->root . '/s1/r1/card.json', '{not json');
        self::assertNull(AgentRunCards::updateRun('s1', 'r1', static fn (array $c): array => $c, $this->root));
    }
}

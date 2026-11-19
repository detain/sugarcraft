<?php

declare(strict_types=1);

namespace SugarCraft\ServeProtocol\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use SugarCraft\ServeProtocol\Discovery\LiveRecord;
use SugarCraft\ServeProtocol\Discovery\ProcessIdentity;
use SugarCraft\ServeProtocol\Tests\Support\TempTree;

final class LiveRecordTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = TempTree::make();
    }

    protected function tearDown(): void
    {
        TempTree::remove($this->root);
    }

    private function write(array $record): void
    {
        file_put_contents($this->root . '/' . LiveRecord::FILE, json_encode($record));
    }

    public function testAMissingRecordHasNoUrl(): void
    {
        self::assertNull(LiveRecord::url($this->root));
    }

    public function testALiveRecordAnswersItsUrl(): void
    {
        $this->write(['pid' => getmypid(), 'url' => 'http://127.0.0.1:9/', 'procStartTime' => null]);

        self::assertSame('http://127.0.0.1:9/', LiveRecord::url($this->root));
    }

    public function testAStaleRecordHasNoUrl(): void
    {
        // A pid that cannot be alive: above any kernel pid_max.
        $this->write(['pid' => 2147483646, 'url' => 'http://127.0.0.1:9/']);

        self::assertNull(LiveRecord::url($this->root));
    }

    public function testAMismatchedStartTimeIsStale(): void
    {
        $this->write(['pid' => getmypid(), 'url' => 'http://127.0.0.1:9/', 'procStartTime' => -1]);

        self::assertNull(LiveRecord::url($this->root));
    }

    public function testARecordWithoutAUrlIsRefused(): void
    {
        $this->write(['pid' => getmypid()]);

        self::assertNull(LiveRecord::url($this->root));
    }

    public function testTheCurrentProcessIsSameProcessWithUnknownStartTime(): void
    {
        self::assertTrue(ProcessIdentity::isSameProcess(getmypid(), null));
    }

    public function testANonPositivePidIsNeverTheSameProcess(): void
    {
        self::assertFalse(ProcessIdentity::isSameProcess(0, null));
    }
}

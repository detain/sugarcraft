<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Core\Recorder as CoreRecorder;

/** @internal Test double for {@see \SugarCraft\Vcr\Cli\WindowsRecordLoop} legs. */
final class FakeLoopRecorder implements CoreRecorder
{
    public string $output = '';
    public string $input = '';

    /** @var list<array{0:int,1:int}> */
    public array $resizes = [];

    public function recordResize(int $cols, int $rows): void
    {
        $this->resizes[] = [$cols, $rows];
    }

    public function recordInputBytes(string $bytes): void
    {
        $this->input .= $bytes;
    }

    public function recordOutput(string $bytes): void
    {
        $this->output .= $bytes;
    }

    public function recordQuit(): void
    {
    }

    public function close(): void
    {
    }
}

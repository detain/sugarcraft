<?php

declare(strict_types=1);

namespace SugarCraft\Video\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Video\AudioPlayer;
use SugarCraft\Video\Decode\GifDecoder;
use SugarCraft\Video\Msg\TickMsg;
use SugarCraft\Video\Player;
use SugarCraft\Video\Render\Mode;
use SugarCraft\Video\Source\Probe;
use SugarCraft\Video\Synthetic;

/**
 * SV-M39/039: the open() spawn shape.
 *
 * Before this step every Player::open() pulled a poster frame and then
 * REOPENED the decoder to rewind it — a second decode child (fresh ffmpeg
 * spawn, grace reap of the first) whose only product was replaying frame 0
 * once more on the first advance. The poster is now retained: one decode
 * child per open, the first paint is the poster, and the first tick shows
 * frame 1 — the playhead/content alignment the seek paths already had.
 * And a pinned fps is a caller declaring they know the stream, so the
 * metadata probe is skipped entirely (totalFrames/audio are its answers).
 *
 * Spawn counting is behavioural: stub ffmpeg/ffprobe children log one line
 * per invocation to a scratch file. ffmpeg emits canned raw RGB24 frames
 * (frame i is the solid fill chr(i+1)) sized from SV_STUB_* env, which
 * scrubbedEnv() passes through untouched (the deny-list targets credential
 * families only).
 */
final class PlayerOpenSpawnTest extends TestCase
{
    private string $dir;

    private string $log;

    private string $subject;

    protected function setUp(): void
    {
        // M3: probe() throws on a LOCAL file ffprobe chokes on; a stubbed
        // probe answers with a clean 24fps/2s video + audio stream, so the
        // shape is exactly the friendly-probe case.
        $this->dir = sys_get_temp_dir() . '/sv-m39-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        $this->log = $this->dir . '/spawns.log';
        $this->subject = $this->dir . '/clip.mp4';
        file_put_contents($this->subject, 'not-a-real-container');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->subject);
        @unlink($this->dir . '/ffmpeg');
        @unlink($this->dir . '/ffprobe');
        @rmdir($this->dir);
    }

    /**
     * Install the logging stubs, swap PATH, and run $fn with the stub env
     * armed (frame geometry/count travel via SV_STUB_* pass-through vars).
     */
    private function withStubChildren(callable $fn): void
    {
        // The ffmpeg stub needs an interpreter PATH-independent of the swap:
        // shebang to the exact php running PHPUnit.
        file_put_contents(
            $this->dir . '/ffmpeg',
            "#!" . PHP_BINARY . "\n"
            . "<?php\n"
            . "file_put_contents(getenv('SV_STUB_LOG'), \"ffmpeg\\n\", FILE_APPEND);\n"
            . "\$w = (int) getenv('SV_STUB_W');\n"
            . "\$h = (int) getenv('SV_STUB_H');\n"
            . "\$n = (int) getenv('SV_STUB_FRAMES');\n"
            . "for (\$i = 0; \$i < \$n; \$i++) {\n"
            . "    echo str_repeat(chr(\$i + 1), \$w * \$h * 3);\n"
            . "}\n",
        );
        chmod($this->dir . '/ffmpeg', 0755);
        file_put_contents(
            $this->dir . '/ffprobe',
            "#!/bin/sh\n"
            . "echo ffprobe >> \"$this->log\"\n"
            . "printf '%s' '{\"streams\":[{\"codec_type\":\"video\",\"width\":64,\"height\":64,"
            . "\"duration\":\"2.0\",\"r_frame_rate\":\"24/1\"},{\"codec_type\":\"audio\"}]}'\n"
            . "exit 0\n",
        );
        chmod($this->dir . '/ffprobe', 0755);

        $saved = [
            'PATH' => getenv('PATH'),
            'SV_STUB_LOG' => getenv('SV_STUB_LOG'),
            'SV_STUB_W' => getenv('SV_STUB_W'),
            'SV_STUB_H' => getenv('SV_STUB_H'),
            'SV_STUB_FRAMES' => getenv('SV_STUB_FRAMES'),
        ];
        // HalfBlock decodes at cellsW x (cellsH * 2) pixels: 12 x 16 (the
        // cell width is above MIN_COLS so no clamp lifts it under the fixture).
        putenv('PATH=' . $this->dir);
        putenv('SV_STUB_LOG=' . $this->log);
        putenv('SV_STUB_W=12');
        putenv('SV_STUB_H=16');
        putenv('SV_STUB_FRAMES=4');
        Probe::clearBinaryCacheForTesting();
        try {
            $fn();
        } finally {
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            Probe::clearBinaryCacheForTesting();
        }
    }

    /**
     * @return list<string>
     */
    private function spawnLines(string $binary): array
    {
        $lines = file_exists($this->log) ? array_filter(explode("\n", (string) file_get_contents($this->log))) : [];

        return array_values(array_filter($lines, static fn (string $l): bool => $l === $binary));
    }

    public function testThePosterRetainOpensWithASingleDecodeChild(): void
    {
        $this->withStubChildren(function (): void {
            $player = Player::open($this->subject, 12, 8, null, Mode::HalfBlock);
            try {
                // The whole point: ONE ffmpeg child, not the old open+rewind
                // pair. The probe still ran (control leg of the skip test).
                $this->assertCount(1, $this->spawnLines('ffmpeg'), 'open must spawn exactly one decode child');
                $this->assertCount(1, $this->spawnLines('ffprobe'));

                // First paint is the retained poster = content frame 0.
                $this->assertSame(0, $player->frameIndex);
                $this->assertNotNull($player->currentFrame);
                $this->assertSame(str_repeat("\x01", 12 * 16 * 3), $this->frameBytes($player));

                // And the playhead is ALIGNED: the first advance shows frame 1
                // (the old rewind made it replay frame 0, one content frame
                // behind what the index claims).
                $playing = $player->play();
                $playing = $this->backdate($playing, 1.0 / 24.0);
                [$advanced, $cmd] = $playing->update(TickMsg::instance());
                $this->assertNotNull($cmd);
                $this->assertSame(1, $advanced->frameIndex);
                $this->assertSame(str_repeat("\x02", 12 * 16 * 3), $this->frameBytes($advanced), 'first tick must show frame 1, not a poster replay');

                $player = $advanced;
            } finally {
                $player->stop();
            }
        });
    }

    public function testFpsOverrideSkipsTheMetadataProbeEntirely(): void
    {
        $this->withStubChildren(function (): void {
            $player = Player::open($this->subject, 12, 8, 30.0, Mode::HalfBlock);
            try {
                $this->assertCount(1, $this->spawnLines('ffmpeg'), 'the decode child is not optional');
                $this->assertCount(0, $this->spawnLines('ffprobe'), 'a pinned fps answers the probe question already');
                $this->assertSame(0, $player->totalFrames, 'duration is a probe answer: overridden fps means no timeline');
                $this->assertNull($this->audioCompanion($player), 'audio presence is a probe answer too');
                // The poster still paints — the skip is about metadata, not frames.
                $this->assertSame(str_repeat("\x01", 12 * 16 * 3), $this->frameBytes($player));
            } finally {
                $player->stop();
            }
        });
    }

    /**
     * The pure-PHP leg needs no stubs: a real synthetic GIF through the
     * retained-poster open, with the content oracle an independent decoder
     * walking the same bytes.
     */
    public function testTheRetainedPosterAlignsWithAnIndependentWalkOfTheSameGif(): void
    {
        $path = Synthetic::generate($this->dir . '/synthetic.gif', 24, 12, 6, 8);

        $oracle = new GifDecoder();
        $oracle->open($path, 12, 8, 12.5, Mode::HalfBlock);
        $frame0 = $oracle->next();
        $frame1 = $oracle->next();
        $this->assertNotNull($frame0);
        $this->assertNotNull($frame1);
        $this->assertNotSame($frame0->bytes, $frame1->bytes, 'the pattern must vary per frame or the pin is vacuous');

        // fps pinned (12.5) — the probe is skipped per M39, so this leg is
        // hermetic even on hosts without ffprobe; the rewind it replaces was
        // the expensive half this test guards.
        $player = Player::open($path, 12, 8, 12.5, Mode::HalfBlock);
        try {
            $this->assertSame(
                $frame0->bytes,
                $this->frameBytes($player),
                'first paint equals the old reopened frame 0 (golden equivalence)',
            );
            $this->assertSame(1, $this->decoderCursor($player), 'the grabbed poster counts as consumed');

            $playing = $player->play();
            $playing = $this->backdate($playing, 1.0 / 12.5);
            [$advanced] = $playing->update(TickMsg::instance());
            $this->assertSame(
                $frame1->bytes,
                $this->frameBytes($advanced),
                'the first advance shows frame 1 — aligned with the seek-path invariant',
            );
            $player = $advanced;
        } finally {
            $player->stop();
            $oracle->close();
        }
    }

    private function frameBytes(Player $player): string
    {
        $this->assertNotNull($player->currentFrame);

        return $player->currentFrame->bytes;
    }

    /**
     * The decoder's own consumed-count. GifDecoder names it frameIndex; the
     * ffmpeg stub-backed leg only asserts the GIF shape, so reading the GIF
     * cursor (and falling back to 1 for the stub decoder, whose consumed
     * count the spawn log already proves via index alignment) is enough.
     */
    private function decoderCursor(Player $player): int
    {
        $property = (new \ReflectionClass($player->decoder))->getProperty('frameIndex');
        $property->setAccessible(true);

        return (int) $property->getValue($player->decoder);
    }

    private function backdate(Player $player, float $seconds): Player
    {
        $mutate = (new \ReflectionClass(Player::class))->getMethod('mutate');
        $mutate->setAccessible(true);

        /** @var Player $next */
        $next = $mutate->invoke($player, [
            'lastTickTime' => \hrtime(true) / 1e9 - $seconds,
        ]);

        return $next;
    }

    private function audioCompanion(Player $player): ?AudioPlayer
    {
        $property = (new \ReflectionClass(Player::class))->getProperty('audioPlayer');
        $property->setAccessible(true);

        /** @var ?AudioPlayer $audio */
        $audio = $property->getValue($player);

        return $audio;
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Media\Video;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Media\Capability\EndpointFamily;
use SugarCraft\Code\Media\Video\FrameLadder;

/**
 * W5.3 pins for the §6.2 snap-to-valid-ladder law (plan_crush_media): the
 * client coalesces UP to the nearest recorded rung, says so in an
 * informational line, refuses below 1 and above the top rung, and falls back
 * to the bare 8k+1 stride for families without a recorded set.
 */
final class FrameLadderTest extends TestCase
{
    private const SGLANG = EndpointFamily::SglangDiffusion;

    public function testHundredSnapsUpToOneTwentyOneWithTheInfoLine(): void
    {
        // The plan's own worked example (W5.3 test row): 100 -> 121 + note.
        $ladder = FrameLadder::snap(100, self::SGLANG);

        self::assertSame(100, $ladder->requested);
        self::assertSame(121, $ladder->frames);
        self::assertSame(21, $ladder->diff);
        self::assertSame(
            'num_frames 100 is not on the sglang-diffusion ladder (81/121/193/257); it was snapped up to 121 (+21)',
            $ladder->note,
        );
    }

    public function testRungRequestsStayPutAndStaySilent(): void
    {
        foreach ([81, 121, 193, 257] as $rung) {
            $ladder = FrameLadder::snap($rung, self::SGLANG);
            self::assertSame($rung, $ladder->frames);
            self::assertSame(0, $ladder->diff);
            self::assertNull($ladder->note, 'a request already on the ladder must not announce a move');
        }
    }

    public function testTinyRequestsSnapUpToTheFirstRungOfARecordedLadder(): void
    {
        // 1 is 8k+1-legal in the abstract but no recorded model publishes a
        // one-frame rung; the nearest rung >= the ask wins.
        $ladder = FrameLadder::snap(1, self::SGLANG);
        self::assertSame(81, $ladder->frames);
        self::assertNotNull($ladder->note);
    }

    public function testJustAboveARungSnapsToTheNextOneNotBackToIt(): void
    {
        $ladder = FrameLadder::snap(82, self::SGLANG);

        self::assertSame(121, $ladder->frames);
        self::assertSame(39, $ladder->diff);
    }

    public function testTopRungItselfIsStillAccepted(): void
    {
        self::assertSame(257, FrameLadder::snap(257, self::SGLANG)->frames);
    }

    public function testAboveTheTopRungIsRefusedNotClamped(): void
    {
        // Growing a render past the recorded ceiling is the caller's decision;
        // the ladder refuses rather than silently capping the request.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refused, not clamped');

        FrameLadder::snap(258, self::SGLANG);
    }

    public function testSubOneRequestsAreRefused(): void
    {
        foreach ([0, -5] as $bad) {
            try {
                FrameLadder::snap($bad, self::SGLANG);
                self::fail("frame count {$bad} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('must be at least 1', $e->getMessage());
            }
        }
    }

    public function testUnrecordedFamilyFallsBackToTheBareStrideLaw(): void
    {
        // 100 -> next 8k+1: remainder (99 % 8) = 3, so +5 -> 105.
        $ladder = FrameLadder::snap(100, EndpointFamily::SdApi);
        self::assertSame(105, $ladder->frames);
        self::assertNotNull($ladder->note);
        self::assertStringContainsString('8k+1 stride', (string) $ladder->note);

        // 10 -> 17, while 9 (already 8k+1) rides untouched.
        self::assertSame(17, FrameLadder::snap(10, EndpointFamily::SdApi)->frames);
        self::assertSame(9, FrameLadder::snap(9, EndpointFamily::SdApi)->frames);
        self::assertNull(FrameLadder::snap(9, EndpointFamily::SdApi)->note);
    }

    public function testEveryRungOnEveryRecordedLadderObeysTheStrideLaw(): void
    {
        // Tripwire: a rung that is not 8k+1 would make the ladder lie about
        // the model contract it records.
        foreach (FrameLadder::LADDERS as $familyValue => $rungs) {
            self::assertNotEmpty($rungs, $familyValue);
            foreach ($rungs as $rung) {
                self::assertSame(0, ($rung - 1) % FrameLadder::STRIDE, $familyValue . ':' . $rung);
            }
        }
    }
}

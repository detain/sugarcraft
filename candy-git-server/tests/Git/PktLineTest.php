<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Git;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\GitServer\Git\PktLine;

use function array_pop;
use function count;
use function ctype_xdigit;
use function exec;
use function fclose;
use function is_executable;
use function pack;
use function proc_close;
use function proc_open;
use function sprintf;
use function str_ends_with;
use function stream_get_contents;
use function strlen;
use function substr;

/**
 * Pins for the single pkt-line codec (r1024/githttp, owner ruling 1b).
 *
 * The last test is the anchor of the whole commit: the bytes a real git
 * 2.43 `upload-pack --stateless-rpc --advertise-refs` child writes must
 * decode through PktLine::decode() cleanly and must start with four ASCII
 * hex digits — the codec agrees with the binary, not with our old emitters.
 */
final class PktLineTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/pktline-' . \uniqid('t', true);
        if (!\mkdir($this->tmpDir, 0700, true) && !is_dir($this->tmpDir)) {
            self::fail('Could not create test directory.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir === '') {
            return;
        }

        exec('rm -rf ' . \escapeshellarg($this->tmpDir));
    }

    public function testEncodeFramesWithAsciiHexNeverRawBytes(): void
    {
        $encoded = PktLine::encode('want ' . \str_repeat('a', 40));

        // 45-byte payload + 4 header = 49 = 0x0031, written as TEXT hex.
        self::assertSame('0031', substr($encoded, 0, 4));
        self::assertTrue(ctype_xdigit(substr($encoded, 0, 4)));
        // The old ad-hoc emitters wrote pack('n', ...) here — 0x00 0x31 raw.
        self::assertNotSame(pack('n', 49), substr($encoded, 0, 4));
        self::assertSame('want ' . \str_repeat('a', 40), substr($encoded, 4));
    }

    public function testEncodeEmptyPayloadIsTheFlushPacket(): void
    {
        self::assertSame('0000', PktLine::encode(''));
        self::assertSame('0000', PktLine::flush());
    }

    public function testEncodeCarriesServiceHeaderWithTrailingNewlineInsideLength(): void
    {
        // git http-backend writes the service header as a pkt-line whose
        // payload ends in LF; 26-byte payload => 0x1e = 30 total bytes.
        $encoded = PktLine::encode("# service=git-upload-pack\n");

        self::assertSame("001e# service=git-upload-pack\n", $encoded);
    }

    public function testEncodeRefusesPayloadOverThePacketCap(): void
    {
        // Flipped 2026-10-24 (r1024/githttp2, review MINOR-2): the cap was
        // pinned at 65535 (0xFFFF), which contradicted this class's own
        // docblock rule and git's LARGE_PACKET_MAX. The spec value 65520 is
        // now enforced on BOTH sides; this pin records the refusal shape
        // under the enforced cap, not whatever the code happens to do.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the 65520-byte packet cap');

        PktLine::encode(\str_repeat('x', PktLine::MAX_PAYLOAD_BYTES + 1));
    }

    public function testEncodeAcceptsPayloadExactlyAtTheCap(): void
    {
        // 65516-byte payload + 4 prefix bytes == the spec's 65520 total.
        // (2026-10-24 flip, same step as the cap: previously pinned 'ffff'.)
        $encoded = PktLine::encode(\str_repeat('x', PktLine::MAX_PAYLOAD_BYTES));

        self::assertSame('fff0', substr($encoded, 0, 4));
        self::assertSame(65520, \hexdec(substr($encoded, 0, 4)));
    }

    public function testDecodeRefusesOversizedPacketLengths(): void
    {
        // r1024/githttp2: decode-side spec enforcement — 65521 total is
        // malformed framing, exactly like a reserved or binary length.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('oversized pkt-line length fff1');

        PktLine::decode('fff1' . \str_repeat('x', 65517));
    }

    public function testDecodeAcceptsPacketExactlyAtTheSpecCap(): void
    {
        $bytes = PktLine::encode(\str_repeat('x', 65516));

        self::assertSame([\str_repeat('x', 65516)], PktLine::decode($bytes));
    }

    public function testDecodeRoundTripsEncoderOutput(): void
    {
        $bytes = PktLine::encode('want ' . \str_repeat('a', 40))
            . PktLine::flush()
            . PktLine::encode('done');

        self::assertSame(['want ' . \str_repeat('a', 40), null, 'done'], PktLine::decode($bytes));
    }

    public function testDecodeAcceptsUppercaseHexPrefixes(): void
    {
        // The spec allows both cases in the length prefix; a tolerant
        // decoder reads either ('000A' == 10 total == 6-byte payload).
        self::assertSame(['abcdef'], PktLine::decode('000Aabcdef'));
        self::assertSame(['abcdef'], PktLine::decode('000aabcdef'));
    }

    public function testDecodeRejectsReservedLengths(): void
    {
        // 0001-0003 are the spec's control-value range below the smallest
        // addressable content packet (0004 = one payload byte).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reserved or invalid pkt-line length');

        PktLine::decode('0001x');
    }

    public function testDecodeRejectsBinaryLengthFraming(): void
    {
        // The retired ad-hoc emitters wrote pack('n', ...) — two raw bytes.
        // The decoder must refuse such frames loudly rather than silently
        // mis-parse them (the defect the pre-fix pins recorded).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed pkt-line length prefix');

        PktLine::decode(pack('n', 6) . 'want');
    }

    public function testDecodeRejectsTruncatedPacket(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('truncated pkt-line');

        PktLine::decode('001eshort');
    }

    public function testIsServiceHeaderMatchesBothPolarities(): void
    {
        self::assertTrue(PktLine::isServiceHeader("# service=git-upload-pack\n", 'git-upload-pack'));
        self::assertTrue(PktLine::isServiceHeader('# service=git-upload-pack', 'git-upload-pack'));
        self::assertFalse(PktLine::isServiceHeader("# service=git-receive-pack\n", 'git-upload-pack'));
        self::assertFalse(PktLine::isServiceHeader("\x01pack", 'git-upload-pack'));
    }

    public function testCodecAgreesWithWhatTheGitBinaryActuallyWrites(): void
    {
        if (!is_executable('/usr/bin/git')) {
            self::markTestSkipped('git binary required for the ground-truth pin; host requirement disclosed in README.');
        }

        $repo = $this->tmpDir . '/gt.git';
        $seed = $this->tmpDir . '/seed';
        \mkdir($repo, 0700, true);
        \mkdir($seed, 0700, true);

        exec('git init -q --bare -b main ' . \escapeshellarg($repo) . ' 2>&1');
        exec('git init -q -b main ' . \escapeshellarg($seed) . ' 2>&1');
        \file_put_contents($seed . '/a.txt', 'hello');
        exec(
            'git -C ' . \escapeshellarg($seed) . ' -c user.email=t@t -c user.name=t add a.txt 2>&1'
            . ' && git -C ' . \escapeshellarg($seed) . ' -c user.email=t@t -c user.name=t commit -qm first 2>&1'
            . ' && git -C ' . \escapeshellarg($seed) . ' push -q ' . \escapeshellarg($repo) . ' HEAD:refs/heads/main 2>&1'
        );

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        $process = proc_open(
            ['git', '-C', $repo, 'upload-pack', '--stateless-rpc', '--advertise-refs', '.'],
            $descriptors,
            $pipes
        );
        self::assertIsResource($process);
        \fclose($pipes[0]);
        $bytes = (string) stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        \fclose($pipes[2]);
        \proc_close($process);
        self::assertSame('', $stderr, 'git advertise-refs must run clean on the fixture');

        $packets = PktLine::decode($bytes);
        self::assertGreaterThanOrEqual(2, count($packets));

        // First advertisement line: "<oid> HEAD\0<capabilities>" with the
        // trailing LF riding INSIDE the framed payload (git 2.43 behaviour).
        $first = (string) $packets[0];
        self::assertSame(1, \preg_match('/^[0-9a-f]{40} HEAD\x00/', $first), 'pkt1 carries oid + NUL caps');
        self::assertStringContainsString('thin-pack', $first);
        self::assertStringContainsString('side-band-64k', $first);
        self::assertTrue(str_ends_with($first, "\n"), 'payload trailing LF rides inside the length');

        // The length prefix on the wire is ASCII hex, and its value equals
        // the decoded payload width + header.
        self::assertSame(1, \preg_match('/^[0-9a-f]{4}$/', substr($bytes, 0, 4)));
        self::assertSame(sprintf('%04x', strlen($first) + 4), substr($bytes, 0, 4));

        // The stream ends on a flush packet (decoded as the null sentinel).
        self::assertNull(array_pop($packets));
        self::assertSame('0000', substr($bytes, -4));
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;

/**
 * Honesty oracle for the documented tie divergence (sugar-diff-030): on
 * files with repeated content several minimal edit scripts exist, and this
 * engine's LCS backtracking (delete-first on ties) may mark a DIFFERENT
 * occurrence than GNU diff — the edit sets are equal in size, the hunk
 * bodies differ in placement. Placement equality vs `/usr/bin/diff` is
 * therefore NOT a valid contract (and the byte-parity suites here only ever
 * pin non-tie shapes); what must always hold is that every patch we emit
 * APPLIES byte-exactly. The corpus below is the six shapes the P2 fuzz
 * probe found place-differently against live GNU diff (git 2.43-era).
 *
 * Binaries are probed, not assumed: missing tools skip loudly rather than
 * false-green.
 */
final class GnuTieApplyOracleTest extends TestCase
{
    private const PATCH_BINARY = '/usr/bin/patch';
    private const DIFF_BINARY = '/usr/bin/diff';

    /**
     * Fuzz-discovered tie shapes (mt_srand(42), alphabet a|b|c): our hunk
     * bodies place the edit on a later/earlier occurrence than GNU's, while
     * both remain minimal.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function tieShapes(): array
    {
        return [
            'rotate abab' => ["b\na\nb\nb\nc\nb\nc\nc\n", "b\nc\na\nb\nb\na\na\nb\nb\n"],
            'double a tail' => ["c\nc\nb\na\nc\na\nb\na\na\n", "c\nc\nb\na\nc\na\na\nb\n"],
            'bb to bbb' => ["b\nb\nc\nc\na\nc\na\n", "c\nb\na\nc\na\nb\nb\nb\n"],
            'aaa run' => ["c\na\na\na\nb\na\nc\na\nb\n", "a\nb\nc\na\nc\nb\nc\nc\n"],
            'ccc run' => ["a\na\nc\na\nb\nc\nc\nc\n", "a\nb\na\na\nc\na\nb\na\n"],
            'short grow' => ["c\nb\na\na\nc\n", "b\nc\na\nb\nb\na\na\nc\nb\n"],
        ];
    }

    /**
     * @dataProvider tieShapes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tieShapes')]
    public function test_every_tie_shape_byte_applies_under_gnu_patch(string $before, string $after): void
    {
        $patch = Diff::compute($before, $after)->unified('f.txt');
        $this->assertNotSame('', $patch);

        $this->assertSame($after, $this->applyPatch($before, $patch), "patch must apply byte-exact\n$patch");
    }

    /**
     * @dataProvider tieShapes
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tieShapes')]
    public function test_our_tie_script_is_minimal_against_the_gnu_oracle(string $before, string $after): void
    {
        if (!is_file(self::DIFF_BINARY)) {
            $this->markTestSkipped('GNU diff is not installed at ' . self::DIFF_BINARY);
        }

        $ours = Diff::compute($before, $after)->hunkText();
        $gnu = self::gnuHunkSection($before, $after);

        // Both engines solve the same LCS exactly at these sizes, so equal
        // edit counts are the version-stable truth: minimal, even when the
        // placement differs.
        $this->assertSame(
            preg_match_all('/^[-+]/m', $gnu),
            preg_match_all('/^[-+]/m', $ours),
            "both scripts must be minimal; ours:\n$ours\nGNU:\n$gnu",
        );
    }

    public function test_our_tie_placement_is_pinned_so_a_rule_change_is_disclosed(): void
    {
        // Delete-first tie rule, recorded bytes: GNU 2.43 places the kept 'a'
        // after '-b'+'-a' here while we anchor it before. This pin is OUR
        // engine's contract (and the disclosure that the shape is
        // placement-divergent) — it deliberately does not shell out.
        $this->assertSame(
            "@@ -4,6 +4,5 @@\n a\n c\n a\n-b\n a\n-a\n+b\n",
            Diff::compute("c\nc\nb\na\nc\na\nb\na\na\n", "c\nc\nb\na\nc\na\na\nb\n")->hunkText(),
        );
    }

    private static function gnuHunkSection(string $before, string $after): string
    {
        $dir = self::scratchDir();
        file_put_contents($dir . '/b.txt', $before);
        file_put_contents($dir . '/a.txt', $after);

        $output = (string) shell_exec('cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg(self::DIFF_BINARY) . ' -U3 b.txt a.txt');
        self::removeDir($dir);

        $start = strpos($output, "\n@@");

        return $start === false ? '' : substr($output, $start + 1);
    }

    private function applyPatch(string $originalBytes, string $patch): string
    {
        if (!is_file(self::PATCH_BINARY)) {
            $this->markTestSkipped('GNU patch is not installed at ' . self::PATCH_BINARY);
        }

        $dir = self::scratchDir();
        file_put_contents($dir . '/f.txt', $originalBytes);
        file_put_contents($dir . '/f.patch', $patch);

        try {
            $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
            $process = proc_open(
                [self::PATCH_BINARY, '--quiet', 'f.txt', 'f.patch'],
                $descriptors,
                $pipes,
                $dir,
            );
            $this->assertIsResource($process, 'patch must start');
            fclose($pipes[0]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(0, $status, "patch must apply cleanly; rc=$status stderr=$stderr patch=\n$patch");

            return (string) file_get_contents($dir . '/f.txt');
        } finally {
            self::removeDir($dir);
        }
    }

    private static function scratchDir(): string
    {
        $dir = sys_get_temp_dir() . '/sugar-diff-tie-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

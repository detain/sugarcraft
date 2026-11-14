<?php

declare(strict_types=1);

namespace SugarCraft\Diff\Tests\Writer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Diff\Diff;
use SugarCraft\Diff\DiffOptions;

/**
 * Acceptance oracle for sugar-diff-022: a patch we emit must APPLY with
 * /usr/bin/patch to bytes exactly equal to the new side — for every
 * combination of final-line terminators, under the no-newline annotation.
 * The pre-022 shared-context fold mis-applied three of these four shapes
 * (P2-probed); the pair rendering (Diff::diffLines terminator sentinel +
 * per-row annotation) makes all four byte-exact.
 *
 * The binary is probed, not assumed: in an environment without GNU patch
 * the arms skip loudly rather than false-green.
 */
final class UnifiedFormatApplyTest extends TestCase
{
    private const PATCH_BINARY = '/usr/bin/patch';

    private static function options(): DiffOptions
    {
        return DiffOptions::new()->withNoNewlineAnnotation();
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function terminatorCombos(): array
    {
        return [
            'old unterminated, new terminated' => ["a\nb\nc", "a\nb\nc\n"],
            'old terminated, new unterminated' => ["a\nb\nc\n", "a\nb\nc"],
            'both unterminated, EOF row changes' => ["a\nb\nz", "a\nb\nw"],
            'both terminated, EOF row changes' => ["a\nb\nz\n", "a\nb\nw\n"],
            'mid-file change plus mismatched terminator' => ["x\nc", "y\nc\n"],
            'mismatched terminator, one-line files' => ['c', "c\n"],
        ];
    }

    /**
     * @dataProvider terminatorCombos
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('terminatorCombos')]
    public function test_the_emitted_patch_applies_to_the_exact_new_bytes(string $before, string $after): void
    {
        $patch = Diff::compute($before, $after, self::options())->unified('f.txt');
        $this->assertNotSame('', $patch, 'every combo is a real change under the option');

        $applied = self::applyPatch($before, $patch);

        $this->assertSame($after, $applied, "GNU patch must reproduce the new side byte-exact\n$patch");
    }

    public function test_the_marker_off_path_stays_byte_identical_to_the_ported_fold(): void
    {
        // The legacy contract: without the annotation option a terminator
        // mismatch folds to nothing at all — the ported candy-code engine
        // never saw the newline fact (byte-for-byte preserved, warts and
        // all; emitting no patch is exactly why the option exists).
        $diff = Diff::compute("a\nb\nc", "a\nb\nc\n");

        $this->assertTrue($diff->isEmpty(), 'the OFF path keeps folding the mismatch away');
        $this->assertSame('', $diff->unified('f'));
    }

    public function test_the_pair_shape_matches_the_gnu_oracle_bytes(): void
    {
        // Cross-checked against live GNU diff -U3 (oracle probe captured in
        // the landing commit): annotation under the OLD-side row when that
        // side is the unterminated one.
        $patch = Diff::compute("a\nb\nc", "a\nb\nc\n", self::options())->unified('f.txt');

        $this->assertSame(
            "--- a/f.txt\n+++ b/f.txt\n@@ -1,3 +1,3 @@\n a\n b\n-c\n\\ No newline at end of file\n+c\n",
            $patch,
        );
    }

    private function applyPatch(string $originalBytes, string $patch): string
    {
        if (!is_file(self::PATCH_BINARY)) {
            self::markTestSkipped('GNU patch is not installed at ' . self::PATCH_BINARY);
        }

        $dir = sys_get_temp_dir() . '/sugar-diff-apply-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
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
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(
                0,
                $status,
                "patch must apply cleanly; rc=$status stdout=$stdout stderr=$stderr patch=\n$patch",
            );

            return (string) file_get_contents($dir . '/f.txt');
        } finally {
            @unlink($dir . '/f.txt');
            @unlink($dir . '/f.patch');
            @unlink($dir . '/f.txt.orig');
            @rmdir($dir);
        }
    }
}

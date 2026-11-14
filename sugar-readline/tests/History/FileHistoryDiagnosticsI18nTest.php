<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Readline\History\FileHistory;

/**
 * S-11 (finding 041): the user-reachable FileHistory diagnostics are keyed.
 *
 * The thrown messages ride Lang::t('history.*') — 15 keys mirrored across
 * all 17 locales — while the 'sugar-readline: ' program tag stays a literal
 * English grep-handle at every call site. The three error_log() channels
 * (destructor flush failure, tryOpen() decline, read-lock degradation) are
 * the recorded developer-facing English exemption: they tail logs, not the
 * user's screen (CALIBER_LEARNINGS 2026-11-15).
 */
final class FileHistoryDiagnosticsI18nTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        T::setLocale('en');
        foreach ($this->dirs as $dir) {
            foreach (@\glob($dir . '/*') ?: [] as $f) {
                @\unlink((string) $f);
            }
            @\rmdir($dir);
        }
        $this->dirs = [];
    }

    private function freshDir(): string
    {
        $dir = \sys_get_temp_dir() . '/rl-i18n-' . bin2hex(random_bytes(4));
        if (!\mkdir($dir, 0700)) {
            $this->fail('cannot create temp dir');
        }
        $this->dirs[] = $dir;
        return $dir;
    }

    public function testEnglishRenderIsByteIdenticalToThePreKeyingMessage(): void
    {
        $dir = $this->freshDir();
        $path = $dir . '/history.txt';
        $history = new FileHistory($path, 0, $dir);
        $history->push('seed');

        // Poison the sidecar: the next write refuses through the symlink arm.
        @\unlink($path . '.lock');
        if (!\symlink($dir . '/victim', $path . '.lock')) {
            $this->fail('unable to plant the lock symlink for the refusal seam');
        }
        clearstatcache();

        try {
            $history->push('boom');
            $this->fail('expected the poisoned lock to refuse the write');
        } catch (\RuntimeException $e) {
            self::assertSame(
                'sugar-readline: refusing symlinked history lock ' . $path . '.lock',
                $e->getMessage(),
                'the keyed en value must render byte-identically to the pre-S-11 message'
            );
        }
    }

    public function testTheSameThrowRendersTranslatedUnderGerman(): void
    {
        $dir = $this->freshDir();
        $path = $dir . '/history.txt';
        $history = new FileHistory($path, 0, $dir);
        $history->push('seed');

        @\unlink($path . '.lock');
        if (!\symlink($dir . '/victim', $path . '.lock')) {
            $this->fail('unable to plant the lock symlink for the refusal seam');
        }
        clearstatcache();

        T::setLocale('de');
        try {
            $history->push('boom');
            $this->fail('expected the poisoned lock to refuse the write under de too');
        } catch (\RuntimeException $e) {
            // The grep-handle survives translation; the body does not.
            self::assertStringStartsWith('sugar-readline: ', $e->getMessage());
            self::assertStringContainsString('Symbolischer Link für history lock verweigert', $e->getMessage());
            self::assertStringNotContainsString('refusing symlinked', $e->getMessage());
        }
    }

    public function testNoUserReachableThrowSiteKeepsInlinedEnglishProse(): void
    {
        $src = (string) \file_get_contents(__DIR__ . '/../../src/History/FileHistory.php');

        $inlined = [
            "'sugar-readline: cannot create history file",
            "'sugar-readline: cannot set 0600 on history file",
            "'sugar-readline: refusing symlinked",
            "'sugar-readline: cannot open history file",
            "'sugar-readline: history path is not a regular file",
            "'sugar-readline: history path changed under us",
            "'sugar-readline: cannot acquire append lock on",
            "'sugar-readline: cannot create temp file for history rewrite in",
            "'sugar-readline: cannot open temp file for history rewrite",
            "'sugar-readline: cannot lock temp file for history rewrite",
            "'sugar-readline: cannot replace history file",
            "'sugar-readline: cannot open history write lock",
            "'sugar-readline: history lock changed under us",
            "'sugar-readline: cannot acquire history write lock",
            "'sugar-readline: cannot create temp file for history clear in",
        ];
        foreach ($inlined as $needle) {
            self::assertSame(0, \substr_count($src, $needle), "throw prose must be keyed, not inlined: {$needle}");
        }

        // Every one of the fifteen catalog keys is actually referenced.
        self::assertSame(20, \substr_count($src, "Lang::t('history."), 'every throw site speaks through Lang');

        // The developer-facing exemption trio stays English on disk.
        self::assertGreaterThan(0, \substr_count($src, 'history flush failed:'));
        self::assertGreaterThan(0, \substr_count($src, 'FileHistory::tryOpen declined'));
        self::assertGreaterThan(0, \substr_count($src, 'history read lock unavailable'));
    }
}

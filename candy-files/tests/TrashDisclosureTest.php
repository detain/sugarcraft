<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\I18n\T;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\Lang;
use SugarCraft\Files\Manager;

/**
 * CF-42 (closes audit 014, 069): "quitting wipes the trash" is shutdown-
 * handler truth only — a crash or SIGKILL leaves the bytes sitting in the
 * 0700 $TMPDIR root, and a relaunch says `nothing to undo` with no
 * recovery hint. The UI must state the real window on every trashed
 * delete (in all 16 catalogues), must NOT promise trash for legs that
 * went fully wrong, and the README must name the location.
 */
final class TrashDisclosureTest extends TestCase
{
    private string $priorLocale;
    private string $tmpDir;
    private \Closure $lister;

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-cf42-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->lister = \SugarCraft\Files\FsLister::lister();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
        @chmod($this->tmpDir, 0755);
        $this->removeDir($this->tmpDir);
        // Sweep ONLY this process's trash roots (the CF-41/074 lesson):
        // sibling lanes run under the mandated parallel K, and the old
        // un-prefixed glob wiped their sessions' bytes.
        foreach (glob(sys_get_temp_dir() . '/candyfiles-trash-' . getmypid() . '-*') ?: [] as $trash) {
            $this->removeDir($trash);
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeDir($path . '/' . $item);
        }
        @rmdir($path);
    }

    public function testATrashedDeleteStatusNamesTheRecoveryWindow(): void
    {
        $file = $this->tmpDir . '/doomed.txt';
        file_put_contents($file, 'bytes for the trash');

        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertNotEmpty($m->activePane()->selected);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $m->confirm);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        $hint = Lang::t('status.deleted_trash_hint');
        $this->assertStringContainsString('deleted 1 entries', $m->status);
        $this->assertStringEndsWith(' ' . $hint, $m->status, 'trashed delete must surface the ephemerality hint');
    }

    public function testAnErroredDeletePromisesNoTrash(): void
    {
        $dir = $this->tmpDir . '/locked';
        mkdir($dir);
        file_put_contents($dir . '/inside.txt', 'x');
        // Non-writable parent: the rename into trash fails AND the permanent
        // unlink fallback fails — every leg errors, nothing reaches trash.
        chmod($dir, 0500);

        $m = Manager::start($dir, $dir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, ' '));
        $this->assertNotEmpty($m->activePane()->selected);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'y'));

        chmod($dir, 0755);
        $this->assertStringContainsString('errors', $m->status, 'the leg must be counted as an error');
        $this->assertStringNotContainsString(
            Lang::t('status.deleted_trash_hint'),
            $m->status,
            'zero bytes reached the trash — no recovery-window promise',
        );
    }

    public function testEveryCatalogueCarriesTheHintLocally(): void
    {
        $files = glob(__DIR__ . '/../lang/*.php') ?: [];
        $this->assertNotEmpty($files);

        T::setLocale('en');
        $english = Lang::t('status.deleted_trash_hint');

        $seen = [];
        foreach ($files as $file) {
            $code = basename($file, '.php');
            T::setLocale($code);
            $value = Lang::t('status.deleted_trash_hint');
            $this->assertNotSame('status.deleted_trash_hint', $value, "$code resolves the key");
            $this->assertNotSame('', trim($value), "$code value is non-empty");
            $this->assertStringNotContainsString('{', $value, "$code hint interpolates nothing");
            if ($code !== 'en') {
                $this->assertNotSame($english, $value, "$code is localized, not an English copy");
            }

            $seen[] = $code;
        }

        $this->assertCount(16, $seen, 'all sixteen shipped catalogues were visited');
    }

    public function testTheReadmeNamesTheTrashRootAndTheRelaunchTruth(): void
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');

        // 069 legs: the location, the crash (last-resort) story, and the
        // empty-undo relaunch — all must sit in the user-facing doc.
        $this->assertStringContainsString('candyfiles-trash-', $readme, 'trash root pattern is named');
        $this->assertStringContainsString('0700', $readme, 'the private mode is named');
        $this->assertStringContainsString('last-resort archive', $readme, 'crash residue is disclosed, not hidden');
        $this->assertStringContainsString('empty undo stack', $readme, 'relaunch says nothing carries over');
    }
}

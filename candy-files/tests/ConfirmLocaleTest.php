<?php

declare(strict_types=1);

namespace SugarCraft\Files\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\ConfirmState;
use SugarCraft\Files\FsLister;
use SugarCraft\Files\Manager;

/**
 * M-7 (step 14): the y/n gate must honor the affirmative each locale's
 * prompt actually TEACHES — de "(j/n)", ru "(д/н)", tr "(e/h)" — while
 * 'y' keeps working everywhere (both-accepted: the gate accepts the
 * literal 'y' OR the active catalogue's confirm.affirmative,
 * case-insensitive, multibyte-safe).
 */
final class ConfirmLocaleTest extends TestCase
{
    private string $tmpDir;
    private \Closure $lister;
    private string $priorLocale;

    protected function setUp(): void
    {
        $this->priorLocale = T::locale();
        $this->tmpDir = sys_get_temp_dir() . '/sugarcraft-confirm-locale-' . uniqid('', true);
        mkdir($this->tmpDir, 0700, true);
        $this->lister = FsLister::lister();
    }

    protected function tearDown(): void
    {
        T::setLocale($this->priorLocale);
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $path): void
    {
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full) === true) {
                $this->removeDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    private function victim(): string
    {
        $file = $this->tmpDir . '/victim.txt';
        file_put_contents($file, 'content');
        return $file;
    }

    /**
     * Arm a delete of the single file in the pane: 'j' steps off the
     * parent sentinel onto it, 'd' arms the confirmation.
     *
     * @return array{0: Manager, 1: string} armed model + victim path
     */
    private function armDeleteOnVictim(): array
    {
        $file = $this->victim();
        $m = Manager::start($this->tmpDir, $this->tmpDir, $this->lister);
        [$m] = $m->update(new KeyMsg(KeyType::Char, 'j'));
        [$armed] = $m->update(new KeyMsg(KeyType::Char, 'd'));
        $this->assertSame(ConfirmState::DeleteSelected, $armed->confirm);
        return [$armed, $file];
    }

    public function testGermanAffirmativeProceeds(): void
    {
        T::setLocale('de');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(ConfirmState::None, $done->confirm);
        $this->assertFileDoesNotExist($file, 'the prompted letter must execute the staged delete');
        $this->assertTrue($done->canUndo());
    }

    public function testEnglishYProceedsUnderGermanPrompt(): void
    {
        T::setLocale('de');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFileDoesNotExist($file, "'y' stays accepted under a localized prompt");
        $this->assertTrue($done->canUndo());
    }

    public function testForeignAffirmativeCancelsUnderGermanPrompt(): void
    {
        T::setLocale('de');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 's'));
        $this->assertSame(ConfirmState::None, $done->confirm);
        $this->assertFileExists($file, "another locale's letter is still 'n' here");
        $this->assertFalse($done->canUndo());
    }

    public function testRussianAffirmativeProceedsMultibyte(): void
    {
        T::setLocale('ru');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'д'));
        $this->assertFileDoesNotExist($file, 'the Cyrillic letter the prompt teaches must work');
        $this->assertTrue($done->canUndo());
    }

    public function testRussianAffirmativeIsCaseInsensitive(): void
    {
        T::setLocale('ru');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'Д'));
        $this->assertFileDoesNotExist($file, 'Shift must not silently cancel a yes');
    }

    public function testTurkishAffirmativeProceeds(): void
    {
        T::setLocale('tr');
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertFileDoesNotExist($file);
    }

    public function testEnglishDefaultAcceptsYAndCancelsEverythingElse(): void
    {
        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertFileExists($file, "the gate's cancel-everything-else law holds");
        $this->assertFalse($done->canUndo());

        [$armed, $file] = $this->armDeleteOnVictim();
        [$done] = $armed->update(new KeyMsg(KeyType::Char, 'y'));
        $this->assertFileDoesNotExist($file);
    }

    /**
     * M-6 (step 15): the executable must activate the detected locale —
     * without this line the 16 catalogues are dead weight. Source-scan pin
     * (the TUI cannot run headless here); delete the call in
     * bin/candy-files and this reddens.
     */
    public function testBinActivatesTheDetectedLocale(): void
    {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/bin/candy-files');
        $activation = strpos($bin, 'T::setLocale(T::detect())');
        $this->assertNotFalse($activation, 'bin must wire locale detection before constructing the program');
        // CF-16 (closes 060): existence alone was the old hole — the call
        // sat BELOW the usage block and the directory refusal, so a German
        // user was refused in English (demonstrated live). Activation must
        // precede the first chrome byte the process can print.
        $usageOut = strpos($bin, "fwrite(STDOUT, Lang::t('cli.usage')");
        $this->assertNotFalse($usageOut, 'the usage leg must exist to be ordered against');
        $this->assertLessThan($usageOut, $activation, 'locale must be live before the usage block prints');
    }
}

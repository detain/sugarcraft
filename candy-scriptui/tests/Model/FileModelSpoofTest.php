<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Model;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\FilePicker\FilePicker;
use SugarCraft\ScriptUi\Model\FileModel;

/**
 * scriptui-47 (finding 045): directory names are untrusted bytes.
 *
 * The picker renders entries through sugar-forms' RenderSafe posture,
 * which by design lets TRUE SGR survive — right for caller-supplied
 * choose items, a spoofing hole for filesystem bytes (a planted
 * `evil\x1b[31m…` dir can hide the ▸ marker or make two rows look
 * identical). The cure is the upstream one-file switch to
 * Sanitize::untrustedForDisplay at FilePicker's clean sites — the foreign
 * rider this test trips once it lands. Until then the rider legs skip and
 * the byte-diet leg below pins what RenderSafe DOES already refuse.
 */
final class FileModelSpoofTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
            $this->dir = '';
        }
    }

    private function riderLanded(): bool
    {
        $file = (string) (new \ReflectionClass(FilePicker::class))->getFileName();

        return str_contains((string) file_get_contents($file), 'untrustedForDisplay');
    }

    public function testPlantedNamesCannotHideTheRowMarkerOrRestyleRows(): void
    {
        if (!$this->riderLanded()) {
            self::markTestSkipped('rider pending: sugar-forms FilePicker still uses RenderSafe (true SGR survives by design); this leg arms when the forms lane switches the clean sites to untrustedForDisplay');
        }

        $dir  = $this->makeTree();
        $view = FileModel::newPrompt(cwd: $dir, showHidden: true)->view();

        self::assertStringNotContainsString("\x1b[31m", $view, 'a planted name must not restyle the frame');
        self::assertStringContainsString('evil', $view, 'the name still lists — visibly, plainly');
    }

    public function testC1ControlBytesNeverRideDirectoryNames(): void
    {
        if (!$this->riderLanded()) {
            self::markTestSkipped('rider pending: RenderSafe cannot distinguish C1 senders by design (finding 045); arms with the untrustedForDisplay switch');
        }

        $view = FileModel::newPrompt(cwd: $this->makeTree(c1: true))->view();

        self::assertStringNotContainsString("\x9b", $view, '8-bit CSI must not survive into the frame');
    }

    public function testEvenTheRenderSafePostureRefusesNonSgrEscapes(): void
    {
        // TRUE today and under the rider: RenderSafe strips the ESC of
        // non-SGR sequences and all C0 controls. A planted name may keep
        // colour bytes until the rider lands, but it may never gain a
        // BEL, an OSC title-setter, or an erase line.
        $view = FileModel::newPrompt(cwd: $this->makeTree(noise: true))->view();

        self::assertStringNotContainsString("\x07", $view);
        self::assertStringNotContainsString("\x1b]", $view, 'OSC introducer must never reach the terminal');
        self::assertStringNotContainsString("\x1b[2K", $view, 'erase-line cannot be smuggled through a name');
        self::assertStringContainsString('noise', $view);
    }

    /**
     * F13-W (CI-watch 2026-11-13): NTFS forbids control bytes (0x00-0x1F) in
     * file names, so a planted 'evil\x1b[31m…' directory simply cannot exist
     * on Windows runners — mkdir refuses and the planted-name surface
     * degenerates. The gate is a filesystem-capability probe (attempt the
     * mkdir), not a uname check: the constraint is filesystem semantics, and
     * any host whose fs answers the same refusal gets the same honest skip.
     */
    private function requireControlByteNames(): void
    {
        $probe = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'scriptui-spoof-probe-' . bin2hex(random_bytes(6));
        if (!mkdir($probe)) {
            self::fail('cannot create the temp probe directory (host tmp malfunction)');
        }

        // The warning IS the NTFS refusal this probe exists to observe.
        if (!@mkdir($probe . DIRECTORY_SEPARATOR . "x\x1b[2K")) {
            @rmdir($probe);
            self::markTestSkipped('this filesystem refuses control-byte directory names (NTFS); the planted-name spoof surface cannot be staged here');
        }

        @rmdir($probe . DIRECTORY_SEPARATOR . "x\x1b[2K");
        @rmdir($probe);
    }

    private function makeTree(bool $c1 = false, bool $noise = false): string
    {
        $this->requireControlByteNames();

        // Tempdir-native join (F13-W): '/' after a 'C:\...\Temp' answer mixes
        // separators on Windows; DIRECTORY_SEPARATOR is '/' on POSIX.
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'scriptui-spoof-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'plain.txt', 'x');
        mkdir($this->dir . DIRECTORY_SEPARATOR . 'evil' . "\x1b[31m" . 'red');
        if ($c1) {
            mkdir($this->dir . DIRECTORY_SEPARATOR . 'c1' . "\x9b" . '31mhost');
        }
        if ($noise) {
            // BEL, OSC title-setter, and CSI-erase smuggled into one name.
            mkdir($this->dir . DIRECTORY_SEPARATOR . 'noise' . "\x07\x1b]0;pwned\x07\x1b[2K");
        }

        return $this->dir;
    }
}

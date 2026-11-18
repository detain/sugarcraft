<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\WindowsPath;

/**
 * The Windows lexical normaliser is pure, so every case runs on POSIX with
 * Windows-style fixtures. Fixtures are double-quoted so `\\` is one backslash
 * only where a test means it.
 */
final class WindowsPathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function withinRows(): iterable
    {
        // [root, path, expected canonical candidate or null for refused]
        yield 'drive direct child' => ["C:\\root", "C:\\root\\inside.txt", "C:\\root\\inside.txt"];
        yield 'lower-case drive in path' => ["C:\\root", "c:\\root\\inside.txt", "C:\\root\\inside.txt"];
        yield 'lower-case drive in root' => ["c:\\root", "C:\\root\\inside.txt", "C:\\root\\inside.txt"];
        yield 'forward slashes' => ["C:\\root", "c:/root/sub/deep.txt", "C:\\root\\sub\\deep.txt"];
        yield 'mixed separators and doubled separators' => ["C:\\root", "C:/root\\sub//deep.txt", "C:\\root\\sub\\deep.txt"];
        yield 'dot segment dropped' => ["C:\\root", "C:\\root\\.\\inside.txt", "C:\\root\\inside.txt"];
        yield 'traversal that stays inside' => ["C:\\root", "C:\\root\\sub\\..\\inside.txt", "C:\\root\\inside.txt"];
        yield 'forward-slash traversal that stays inside' => ["C:\\root", "c:/root/sub/../inside.txt", "C:\\root\\inside.txt"];
        yield 'relative path joined onto root' => ["C:\\root", "sub\\deep.txt", "C:\\root\\sub\\deep.txt"];
        yield 'relative traversal that stays inside' => ["C:\\root", "sub\\..\\inside.txt", "C:\\root\\inside.txt"];
        yield 'trailing separator on root' => ["C:\\root\\", "C:\\root\\inside.txt", "C:\\root\\inside.txt"];
        yield 'root itself' => ["C:\\root", "C:\\root", "C:\\root"];
        yield 'root itself with trailing separator' => ["C:\\root", "C:\\root\\", "C:\\root"];
        yield 'drive root contains entry' => ["C:\\", "C:\\Windows", "C:\\Windows"];
        yield 'UNC direct child' => ["\\\\server\\share", "\\\\server\\share\\a.txt", "\\\\server\\share\\a.txt"];
        yield 'UNC forward slashes' => ["//server/share", "//server/share/sub/a.txt", "\\\\server\\share\\sub\\a.txt"];
        yield 'UNC traversal that stays inside' => ["\\\\server\\share", "\\\\server\\share\\sub\\..\\a.txt", "\\\\server\\share\\a.txt"];
        yield 'UNC root itself' => ["\\\\server\\share", "\\\\server\\share", "\\\\server\\share\\"];

        // A canonical root is trusted, so a real name that looks like an 8.3 alias
        // still anchors the jail. Only the caller's own path is checked for aliases.
        yield 'root with a literal tilde-digit segment' => ["C:\\work\\proj~2", "C:\\work\\proj~2\\a.txt", "C:\\work\\proj~2\\a.txt"];
        yield 'relative path under a tilde-digit root' => ["C:\\work\\proj~2", "sub\\deep.txt", "C:\\work\\proj~2\\sub\\deep.txt"];
        yield 'tilde-digit root itself' => ["C:\\work\\proj~2", "C:\\work\\proj~2", "C:\\work\\proj~2"];
        yield 'absolute path typed under a tilde-digit root' => ["C:\\work\\proj~2", "C:\\work\\proj~2\\sub\\a.txt", "C:\\work\\proj~2\\sub\\a.txt"];
        yield 'tilde-digit UNC share root' => ["\\\\server\\share~2", "\\\\server\\share~2\\a.txt", "\\\\server\\share~2\\a.txt"];

        // Refusals: each row must be null, never a path.
        yield 'sibling sharing a prefix' => ["C:\\root", "C:\\rootevil\\x.txt", null];
        yield 'traversal past the root' => ["C:\\root", "C:\\root\\..\\outside\\x.txt", null];
        yield 'traversal past the drive root' => ["C:\\root", "C:\\root\\..\\..\\x.txt", null];
        yield 'relative traversal past the root' => ["C:\\root", "..\\outside\\x.txt", null];
        yield 'other drive' => ["C:\\root", "D:\\root\\x.txt", null];
        yield 'segment case differs' => ["C:\\root", "c:\\ROOT\\x.txt", null];
        yield 'rooted without a drive' => ["C:\\root", "\\x.txt", null];
        yield 'rooted with forward slash' => ["C:\\root", "/etc/passwd", null];
        yield 'drive-relative name' => ["C:\\root", "C:x.txt", null];
        yield 'bare drive' => ["C:\\root", "C:", null];
        yield 'alternate data stream' => ["C:\\root", "C:\\root\\file.txt:secret", null];
        yield 'reserved CON' => ["C:\\root", "C:\\root\\CON", null];
        yield 'reserved NUL with extension' => ["C:\\root", "C:\\root\\nul.txt", null];
        yield 'reserved COM1 with extension' => ["C:\\root", "C:\\root\\COM1.log", null];
        yield 'reserved LPT9' => ["C:\\root", "C:\\root\\lpt9", null];
        yield 'reserved CONIN$' => ["C:\\root", "C:\\root\\CONIN\$", null];
        yield 'trailing dot' => ["C:\\root", "C:\\root\\file.", null];
        yield 'trailing space' => ["C:\\root", "C:\\root\\file ", null];
        yield 'leading space' => ["C:\\root", "C:\\root\\ file", null];
        yield '8.3 short name' => ["C:\\root", "C:\\root\\PROGRA~1\\x", null];
        yield '8.3 short name under a tilde-digit root' => ["C:\\work\\proj~2", "C:\\work\\proj~2\\PROGRA~1\\x", null];
        yield 'relative 8.3 short name under a tilde-digit root' => ["C:\\work\\proj~2", "PROGRA~1\\x", null];
        yield 'traversal past a tilde-digit root' => ["C:\\work\\proj~2", "..\\other\\a.txt", null];
        yield 'reserved name still refused under a tilde-digit root' => ["C:\\work\\proj~2", "C:\\work\\proj~2\\CON", null];
        yield 'wildcard character' => ["C:\\root", "C:\\root\\a*b", null];
        yield 'control character' => ["C:\\root", "C:\\root\\a\x01b", null];
        yield 'NUL byte' => ["C:\\root", "C:\\root\\x\0", null];
        yield 'extended-length prefix' => ["C:\\root", "\\\\?\\C:\\root\\x", null];
        yield 'device namespace prefix' => ["C:\\root", "\\\\.\\C:\\root\\x", null];
        yield "UNC without a share name" => ["\\\\server\\share", "\\\\server", null];
        yield "UNC with a different share" => ["\\\\server\\share", "\\\\server\\other\\a.txt", null];
        yield 'UNC sibling sharing a prefix' => ["\\\\server\\share", "\\\\server\\sharex\\a.txt", null];
        yield 'UNC traversal past the share' => ["\\\\server\\share", "\\\\server\\share\\..\\..\\x", null];
        yield 'drive path against UNC root' => ["\\\\server\\share", "C:\\share\\a.txt", null];
        yield 'UNC path against drive root' => ["C:\\root", "\\\\server\\root\\a.txt", null];
        yield 'malformed root' => ["root", "C:\\root\\x", null];
    }

    #[DataProvider('withinRows')]
    public function testWithinNormalisesOrRefuses(string $root, string $path, ?string $expected): void
    {
        $this->assertSame($expected, WindowsPath::within($root, $path));
    }

    public function testADriveLetterIsUpperCasedInTheCanonicalForm(): void
    {
        $this->assertSame("D:\\data\\x", WindowsPath::collapse("d:\\data\\x"));
    }

    public function testCollapseRefusesADotDotAboveTheAnchor(): void
    {
        $this->assertNull(WindowsPath::collapse("C:\\..\\x"));
        $this->assertNull(WindowsPath::collapse("\\\\server\\share\\..\\x"));
    }

    public function testCollapseAppliesDotDotAfterNormalisation(): void
    {
        $this->assertSame("C:\\b", WindowsPath::collapse("C:\\a\\..\\b"));
    }

    public function testAbsolutiseKeepsDotDotForTheLinkCheck(): void
    {
        $this->assertSame("C:\\root\\sub\\..\\x", WindowsPath::absolutise("C:\\root", "sub\\..\\x"));
    }

    /**
     * Regression. A tilde-digit root typed in another case must still be trusted,
     * because NTFS ignores case. The FS-backed branch cannot run on POSIX, so this
     * pins the pure resolver it calls first. The tail keeps its 8.3 check.
     */
    public function testATildeRootTypedInAnotherCaseIsStillTrusted(): void
    {
        $this->assertSame("C:\\WORK\\proj~2\\a.txt", WindowsPath::absolutise("C:\\work\\proj~2", "c:\\WORK\\proj~2\\a.txt"));
        $this->assertSame("C:\\work\\PROJ~2\\sub\\a.txt", WindowsPath::absolutise("C:\\work\\proj~2", "C:\\work\\PROJ~2\\sub\\a.txt"));
        $this->assertNull(WindowsPath::absolutise("C:\\work\\proj~2", "c:\\WORK\\proj~2\\PROGRA~1\\x"));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function absoluteRows(): iterable
    {
        yield 'drive with backslash' => ["C:\\x", true];
        yield 'drive with slash' => ["c:/x", true];
        yield 'UNC' => ["\\\\server\\share", true];
        yield 'UNC with slashes' => ["//server/share", true];
        yield 'extended prefix counts as absolute, then refused' => ["\\\\?\\C:\\x", true];
        yield 'bare drive' => ["C:", false];
        yield 'drive-relative' => ["C:x", false];
        yield 'rooted' => ["\\x", false];
        yield 'relative' => ["x\\y", false];
    }

    #[DataProvider('absoluteRows')]
    public function testIsAbsoluteRecognisesOnlyAnchoredPaths(string $path, bool $expected): void
    {
        $this->assertSame($expected, WindowsPath::isAbsolute($path));
    }

    public function testPartsSplitsAnAnchorFromItsSegments(): void
    {
        $this->assertSame(["C:", ["a", "b"]], WindowsPath::parts("c:\\a\\b"));
        $this->assertSame(["\\\\server\\share", ["a"]], WindowsPath::parts("\\\\server\\share\\a"));
        $this->assertNull(WindowsPath::parts("\\\\?\\C:\\a"));
    }

    public function testRenderJoinsAnchorAndSegmentsWithBackslashes(): void
    {
        $this->assertSame("C:\\a\\b", WindowsPath::render("C:", ["a", "b"]));
        $this->assertSame("C:\\", WindowsPath::render("C:", []));
    }
}

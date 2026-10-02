<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\WritePathScope;

/**
 * The shared write-target judgement behind `accept-edits`' Edit/Write grant
 * (audit F-P4) and `auto`'s path classification (audit F-P3(b)).
 *
 * Two halves, because the class reads a path two ways: lexically when no root
 * is known, and resolved against a real root on disk when one is. The on-disk
 * half builds a throwaway tree so a symlink can be shown to be followed.
 */
final class WritePathScopeTest extends TestCase
{
    private string $root = '';
    private string $outside = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/wps-' . bin2hex(random_bytes(6));
        $this->root = $base . '/proj';
        $this->outside = $base . '/outside';
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/.git/hooks', 0777, true);
        mkdir($this->outside, 0777, true);
        file_put_contents($this->root . '/src/a.php', '<?php');
        symlink($this->outside, $this->root . '/escape');
        symlink($this->root . '/.git', $this->root . '/notes');
    }

    protected function tearDown(): void
    {
        $base = \dirname($this->root);
        if ($base === '' || !is_dir($base)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            is_link($path) || !$item->isDir() ? unlink($path) : rmdir($path);
        }
        rmdir($base);
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function lexicalCases(): array
    {
        return [
            'relative file'           => ['./src/a.php', WritePathScope::INSIDE],
            'bare relative'           => ['src/a.php', WritePathScope::INSIDE],
            'dotdot that returns'     => ['src/../b.php', WritePathScope::INSIDE],
            'escapes'                 => ['../x.php', WritePathScope::OUTSIDE],
            'escape then re-descend'  => ['../proj/x.php', WritePathScope::OUTSIDE],
            'the root itself'         => ['.', WritePathScope::OUTSIDE],
            'absolute, no root known' => ['/etc/hosts', WritePathScope::OUTSIDE],
            'tilde'                   => ['~/.bashrc', WritePathScope::OUTSIDE],
            '.git hook'               => ['./.git/hooks/pre-commit', WritePathScope::PROTECTED],
            'policy file'             => ['.sugar-crush/settings.json', WritePathScope::PROTECTED],
            'MCP roster'              => ['.mcp.json', WritePathScope::PROTECTED],
            'uppercase .GIT'          => ['.GIT/config', WritePathScope::PROTECTED],
            'lookalike .gitignore'    => ['.gitignore', WritePathScope::INSIDE],
            'lookalike .github'       => ['.github/workflows/ci.yml', WritePathScope::INSIDE],
            'empty'                   => ['', WritePathScope::OUTSIDE],
            'not a string'            => [['a.php'], WritePathScope::OUTSIDE],
            'absent'                  => [null, WritePathScope::OUTSIDE],
            'NUL byte'                => ["a\0b", WritePathScope::OUTSIDE],
        ];
    }

    #[DataProvider('lexicalCases')]
    public function testWithoutARootOnlyTheSpellingIsJudged(mixed $path, string $expected): void
    {
        self::assertSame($expected, WritePathScope::of($path, null));
        self::assertSame($expected, WritePathScope::of($path, ''), 'an empty root is no root');
    }

    public function testWithARootAnInRootPathIsInsideHoweverItIsSpelled(): void
    {
        self::assertSame(WritePathScope::INSIDE, WritePathScope::of('src/a.php', $this->root));
        self::assertSame(WritePathScope::INSIDE, WritePathScope::of($this->root . '/src/a.php', $this->root));
        // Write creates parents, so a missing directory below the root is inside.
        self::assertSame(WritePathScope::INSIDE, WritePathScope::of('docs/new/x.md', $this->root));
    }

    public function testWithARootAnAbsolutePathElsewhereIsOutside(): void
    {
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of('/etc/hosts', $this->root));
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of($this->outside . '/x', $this->root));
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of('../outside/x', $this->root));
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of('~/x', $this->root));
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of($this->root, $this->root));
    }

    /**
     * The reason the root branch resolves rather than spells: both of these
     * are contained relative spellings that the lexical branch would call
     * ordinary.
     */
    public function testWithARootSymlinksAreFollowed(): void
    {
        self::assertSame(
            WritePathScope::OUTSIDE,
            WritePathScope::of('escape/x.php', $this->root),
            'a symlink out of the project is outside, however contained its spelling',
        );
        self::assertSame(
            WritePathScope::PROTECTED,
            WritePathScope::of('notes/hooks/pre-commit', $this->root),
            'a symlink onto .git is .git',
        );
        self::assertSame(WritePathScope::PROTECTED, WritePathScope::of('src/../.git/config', $this->root));
        self::assertSame(WritePathScope::PROTECTED, WritePathScope::of($this->root . '/.git/hooks/x', $this->root));
    }

    /**
     * A root whose OWN path crosses a protected name must not make every file
     * in it protected: an absolute spelling is judged below the root only.
     */
    public function testARootUnderAProtectedNameDoesNotProtectItsWholeTree(): void
    {
        $nested = $this->root . '/.git/worktree-like';
        mkdir($nested);

        self::assertSame(WritePathScope::INSIDE, WritePathScope::of($nested . '/a.php', $nested));
        self::assertSame(WritePathScope::INSIDE, WritePathScope::of('a.php', $nested));
    }

    public function testAMissingRootFailsClosed(): void
    {
        self::assertSame(WritePathScope::OUTSIDE, WritePathScope::of('a.php', $this->root . '/nope'));
    }
}

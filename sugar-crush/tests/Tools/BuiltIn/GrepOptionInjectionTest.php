<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Grep;

/**
 * Audit F-J1 — model-supplied Grep arguments must never reach grep as options.
 *
 * The defect: execute() built `grep -rn … '<pattern>' '<path>'` with no `-e`
 * and no `--`. escapeshellarg() stops shell injection, not option injection,
 * so a pattern of `-ReSECRET` was parsed as `-R` (dereference every symlink)
 * plus `-e SECRET`, and grep walked a repo-shipped `link -> ../outside` out of
 * the PathJail; `-f FILE`, `--devices=read` and friends were reachable the
 * same way.
 *
 * The fixture is that attack in miniature: a workspace root holding a symlink
 * to a sibling directory with a secret in it. Every case asserts BOTH halves:
 * the secret never appears, AND the option-shaped pattern was searched for as
 * TEXT — the second half is what fails on the old command line for every
 * spelling, including the ones (`-R`, `--devices=read`) that would otherwise
 * have quietly consumed the path operand as their pattern.
 *
 * The process cwd is moved into the root for each test: an option-injected
 * call that swallows the path operand makes `grep -r` default to `.`, and
 * pinning `.` to the fixture keeps a regression bounded to it rather than to
 * whatever directory phpunit was launched from.
 */
final class GrepOptionInjectionTest extends TestCase
{
    private const SECRET = 'SECRET_TOKEN=hunter2';

    private string $base = '';
    private string $root = '';
    private string $patternFile = '';
    private string $previousCwd = '';

    protected function setUp(): void
    {
        $this->base = realpath(sys_get_temp_dir()) . '/crush-grep-optinj-' . bin2hex(random_bytes(6));
        $this->root = $this->base . '/root';
        mkdir($this->root, 0o777, true);
        mkdir($this->base . '/outside', 0o777, true);

        file_put_contents($this->base . '/outside/creds.txt', self::SECRET . "\n");
        // A pattern list an `-f` injection would read: it matches the secret.
        $this->patternFile = $this->base . '/outside/patterns.txt';
        file_put_contents($this->patternFile, "SECRET\n");
        // What a cloned hostile repo can ship: git stores symlinks verbatim.
        symlink('../outside', $this->root . '/innocent_link');

        file_put_contents($this->root . '/inner.txt', "a needle in the root\n");

        $this->previousCwd = (string) getcwd();
        chdir($this->root);
    }

    protected function tearDown(): void
    {
        if ($this->previousCwd !== '') {
            chdir($this->previousCwd);
        }
        self::removeTree($this->base);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function optionShapedPatterns(): array
    {
        return [
            '-R plus -e, matching anything' => ['-Re.'],
            '-R plus -e SECRET (the audit repro)' => ['-ReSECRET'],
            'bare -R' => ['-R'],
            'long dereference flag' => ['--dereference-recursive'],
            'devices=read' => ['--devices=read'],
            'pattern file' => ['-f%PATTERN_FILE%'],
        ];
    }

    #[DataProvider('optionShapedPatterns')]
    public function testAnOptionShapedPatternIsSearchedForAsTextAndNeverEscapesTheRoot(string $pattern): void
    {
        $pattern = str_replace('%PATTERN_FILE%', $this->patternFile, $pattern);
        file_put_contents($this->root . '/flags.txt', $pattern . "\n");

        $content = (new Grep($this->root))->execute([
            'pattern' => $pattern,
            'path' => '.',
            'description' => 'option-shaped pattern',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
        $this->assertStringNotContainsString('innocent_link', $content);
        $this->assertStringNotContainsString('creds.txt', $content);
        $this->assertStringContainsString($this->root . '/flags.txt:1:' . $pattern, $content);
    }

    public function testALiteralDashPatternMatchesItsOwnText(): void
    {
        file_put_contents($this->root . '/dash.txt', "keep\n-foo marks the spot\n");

        $content = (new Grep($this->root))->execute([
            'pattern' => '-foo',
            'path' => '.',
            'description' => 'leading dash pattern',
        ])->content();

        $this->assertStringContainsString($this->root . '/dash.txt:2:-foo marks the spot', $content);
    }

    public function testAnOrdinaryPatternStillMatchesInsideTheRoot(): void
    {
        $result = (new Grep($this->root))->execute([
            'pattern' => 'needle',
            'path' => '.',
            'description' => 'ordinary pattern',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString($this->root . '/inner.txt:1:a needle in the root', $result->content());
    }

    /**
     * The `-r` symlink hatch stays as documented: a link MET during the walk
     * is not followed, so a plain search for the secret finds nothing.
     */
    public function testAPlainSearchDoesNotFollowAnOutOfRootLink(): void
    {
        $content = (new Grep($this->root))->execute([
            'pattern' => 'SECRET',
            'path' => '.',
            'description' => 'plain secret search',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
    }

    /**
     * `--include=<glob>` is one argv token, so an option-shaped glob is the
     * option's value: it filters filenames and enables nothing.
     */
    #[DataProvider('optionShapedIncludes')]
    public function testAnOptionShapedIncludeIsOnlyAFilenameGlob(string $include): void
    {
        $content = (new Grep($this->root))->execute([
            'pattern' => 'SECRET',
            'path' => '.',
            'include' => $include,
            'description' => 'option-shaped include',
        ])->content();

        $this->assertStringNotContainsString('hunter2', $content);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function optionShapedIncludes(): array
    {
        return [
            'bare -R' => ['-R'],
            'long dereference flag' => ['--dereference-recursive'],
            'include smuggling a second option' => ['*.txt --dereference-recursive'],
        ];
    }

    /**
     * The unrooted instance (tests, embedders) passes `path` through as given,
     * so a directory literally named `-R` used to be read by grep as the `-R`
     * flag — leaving no file operand, so grep dereference-walked `.` instead.
     * `--` makes it the directory it is.
     */
    public function testADashNamedDirectoryIsSearchedAsADirectory(): void
    {
        mkdir($this->root . '/-R');
        file_put_contents($this->root . '/-R/inside.txt', "SECRET-free needle\n");

        $grep = new Grep();

        $secret = $grep->execute(['pattern' => 'SECRET', 'path' => '-R', 'description' => 'x'])->content();
        $this->assertStringNotContainsString('hunter2', $secret);

        $needle = $grep->execute(['pattern' => 'needle', 'path' => '-R', 'description' => 'x'])->content();
        $this->assertStringContainsString('-R/inside.txt:1:SECRET-free needle', $needle);
    }

    /**
     * A non-string argument is a malformed call answered as an error, not a
     * TypeError out of escapeshellarg().
     *
     * @param array<string, mixed> $args
     */
    #[DataProvider('nonStringArguments')]
    public function testANonStringArgumentIsAnErrorResultNotACrash(array $args, string $named): void
    {
        $result = (new Grep($this->root))->execute($args + ['description' => 'x']);

        $this->assertTrue($result->isError());
        $this->assertSame("Error: $named must be a string", $result->content());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function nonStringArguments(): array
    {
        return [
            'pattern array' => [['pattern' => ['-R'], 'path' => '.'], 'pattern'],
            'path number' => [['pattern' => 'x', 'path' => 7], 'path'],
            'include array' => [['pattern' => 'x', 'path' => '.', 'include' => ['-R']], 'include'],
        ];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            // is_link() FIRST: is_dir() follows a symlink, so recursing would
            // empty the link's target instead of removing the link.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }
            self::removeTree($path);
        }

        rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\BuiltIn\Grep;

/**
 * Audit F-J2 — Grep must never open a secret file, whatever the ignore rules say.
 *
 * The defect: `Grep pattern="=" path="." include_ignored=true` printed
 * `.env:1:DB_PASSWORD=s3cr3t`. ProtectFilesHook cannot screen a DIRECTORY
 * search (`.` contains `.env`), `grep -r` reads dotfiles, and the only thing
 * that had kept the hit out was the `.gitignore` output filter — which
 * `include_ignored: true` switches off. `.git/` was the same story: IgnoreRules
 * excludes it by default and `include_ignored` empties that list.
 *
 * The fixture is a repo that did gitignore its `.env` (the favourable case for
 * the old code) plus a `.git/config` carrying a token in its remote URL.
 */
final class GrepSecretFileTest extends TestCase
{
    private const SECRETS = ['DB_PASSWORD=s3cr3t', 'LOCAL_TOKEN=l0cal', 'DIRENV_KEY=d1renv', 'ghp_t0ken'];

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()) . '/crush-grep-secret-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        mkdir($this->root . '/.git/worktrees/wt', 0o777, true);

        file_put_contents($this->root . '/.gitignore', ".env\n.env.local\n.envrc\n");
        file_put_contents($this->root . '/.env', "DB_PASSWORD=s3cr3t\n");
        file_put_contents($this->root . '/.env.local', "LOCAL_TOKEN=l0cal\n");
        file_put_contents($this->root . '/.envrc', "export DIRENV_KEY=d1renv\n");
        file_put_contents($this->root . '/.git/config', "[remote \"origin\"]\n\turl = https://x:ghp_t0ken@github.com/a/b\n");
        file_put_contents($this->root . '/.git/worktrees/wt/config', "url = https://x:ghp_t0ken@github.com/a/b\n");
        file_put_contents($this->root . '/.env.example', "DB_PASSWORD=\n");
        file_put_contents($this->root . '/src/a.php', "<?php \$url = 'x';\n");
        file_put_contents($this->root . '/src/b.txt', "url = plain text\n");
        // A link with an innocent name: PathJail hands grep the realpath, so a
        // named symlink arrives as `.env` and the exclude still applies.
        symlink('.env', $this->root . '/notes.txt');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('searches')]
    public function testNoSecretReachesTheResult(array $args): void
    {
        $content = (new Grep($this->root))->execute($args + ['description' => 'x'])->content();

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $content, json_encode($args) . " leaked {$secret}");
        }
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function searches(): array
    {
        return [
            'the audit repro' => [['pattern' => '=', 'path' => '.', 'include_ignored' => true]],
            'default rules' => [['pattern' => '=', 'path' => '.']],
            'secret names via include' => [['pattern' => '=', 'path' => '.', 'include' => '.env*', 'include_ignored' => true]],
            'the file itself' => [['pattern' => '=', 'path' => '.env', 'include_ignored' => true]],
            'a symlink to it' => [['pattern' => '=', 'path' => 'notes.txt', 'include_ignored' => true]],
            '.git by name' => [['pattern' => 'url', 'path' => '.git', 'include_ignored' => true]],
            'inside .git' => [['pattern' => 'url', 'path' => '.git/worktrees', 'include_ignored' => true]],
        ];
    }

    /**
     * The other half: the search still works. Ordinary files are found, and
     * the model's `include` still NARROWS — it has to come before the secret
     * excludes on the command line, or GNU grep treats the excludes as the
     * first file option and searches every file the include does not name.
     */
    public function testOrdinaryFilesAreStillSearchedAndIncludeStillNarrows(): void
    {
        $grep = new Grep($this->root);

        $all = $grep->execute(['pattern' => 'url', 'path' => '.', 'include_ignored' => true, 'description' => 'x'])->content();
        $this->assertStringContainsString('src/a.php', $all);
        $this->assertStringContainsString('src/b.txt', $all);

        $php = $grep->execute(['pattern' => 'url', 'path' => '.', 'include' => '*.php', 'description' => 'x'])->content();
        $this->assertStringContainsString('src/a.php', $php);
        $this->assertStringNotContainsString('src/b.txt', $php);
    }
}

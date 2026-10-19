<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Tools\BuiltIn\Grep;

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
 *
 * SHL-4 runs every case under BOTH engines. The rg legs only enter the dataset
 * when the host actually has rg — the provider inspects the capability the same
 * way the boot wiring does, so an rg-less container gets the identical suite it
 * got before, not a skipped-test farm. The engine-critical delta the port had
 * to discover by measurement: rg's `--glob` screening reaches a WALK but not an
 * explicitly named FILE operand (rg printed `SECRET=1` for `path: ".env"`), so
 * the tool screens those operands itself before spawning (the door in
 * {@see Grep::buildCommand()}); these explicit-operand cases are the door's
 * behavioural proof.
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
        // named symlink arrives as `.env` and the exclude still applies — and
        // the rg door checks the same resolved name it receives.
        symlink('.env', $this->root . '/notes.txt');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $args
     */
    #[DataProvider('searchesUnderBothEngines')]
    public function testNoSecretReachesTheResult(array $args, bool $rg): void
    {
        $content = (new Grep($this->root, rgAvailable: $rg))->execute($args + ['description' => 'x'])->content();

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
     * Every search × every engine this host can actually run.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function searchesUnderBothEngines(): array
    {
        $cases = [];
        foreach (self::searches() as $label => [$args]) {
            foreach (self::hostEngines() as $rg) {
                $cases[$label . ($rg ? ' [rg]' : ' [grep]')] = [$args, $rg];
            }
        }

        return $cases;
    }

    /**
     * The engines available to this host: grep always (the fallback is the
     * only engine an rg-less box has), rg when the boot probe finds it.
     *
     * @return list<bool>
     */
    private static function hostEngines(): array
    {
        return Bootstrap::capabilityPresent('rg') ? [false, true] : [false];
    }

    /**
     * The other half: the search still works. Ordinary files are found, and
     * the model's `include` still NARROWS — it has to come before the secret
     * excludes on the command line on BOTH engines, for DIFFERENT reasons: GNU
     * grep decides a double-matching file by its FIRST matching glob option,
     * while rg's globset lets the LAST match win. One argv order serves both,
     * and swapping it leaks (grep) or empties (rg) — pinned by
     * `GrepEngineDifferentialTest`.
     */
    #[DataProvider('engines')]
    public function testOrdinaryFilesAreStillSearchedAndIncludeStillNarrows(bool $rg): void
    {
        $grep = new Grep($this->root, rgAvailable: $rg);

        $all = $grep->execute(['pattern' => 'url', 'path' => '.', 'include_ignored' => true, 'description' => 'x'])->content();
        $this->assertStringContainsString('src/a.php', $all);
        $this->assertStringContainsString('src/b.txt', $all);

        $php = $grep->execute(['pattern' => 'url', 'path' => '.', 'include' => '*.php', 'description' => 'x'])->content();
        $this->assertStringContainsString('src/a.php', $php);
        $this->assertStringNotContainsString('src/b.txt', $php);
    }

    /** @return array<string, array{0: bool}> */
    public static function engines(): array
    {
        $cases = [];
        foreach (self::hostEngines() as $rg) {
            $cases[$rg ? 'rg' : 'grep'] = [$rg];
        }

        return $cases;
    }

    /**
     * W1-g handoff (0.14-c follow-up): key material is kept out of a directory
     * walk the same way `.env` is. `Grep pattern="PRIVATE KEY" path="."` used to
     * print every key line in the tree, because ProtectFilesHook refuses only a
     * search that NAMES the key. The `.pub` halves stay searchable, matching the
     * hook, which lets them be read.
     */
    #[DataProvider('engines')]
    public function testKeyMaterialIsNeverSearchedButPublicKeysAre(bool $rg): void
    {
        mkdir($this->root . '/keys');
        $private = ['server.key', 'DEPLOY.PEM', 'tls.pem', 'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519', 'id_rsa_work', 'id_ed25519_sk', 'id_ecdsa.bak'];
        foreach ($private as $name) {
            file_put_contents($this->root . '/keys/' . $name, "-----BEGIN PRIVATE KEY----- {$name}\n");
        }
        foreach (['id_rsa.pub', 'id_ed25519_sk.pub', 'notes.md'] as $name) {
            file_put_contents($this->root . '/keys/' . $name, "-----BEGIN PRIVATE KEY----- {$name}\n");
        }

        foreach ([['path' => '.'], ['path' => 'keys', 'include_ignored' => true], ['path' => 'keys', 'include' => '*']] as $args) {
            $content = (new Grep($this->root, rgAvailable: $rg))->execute($args + ['pattern' => 'PRIVATE KEY', 'description' => 'x'])->content();

            foreach ($private as $name) {
                $this->assertStringNotContainsString("PRIVATE KEY----- {$name}\n", $content, json_encode($args) . " searched {$name}");
            }
            $this->assertStringContainsString('keys/id_rsa.pub:1:', $content, 'a public key is meant to be shared');
            $this->assertStringContainsString('keys/id_ed25519_sk.pub:1:', $content);
            $this->assertStringContainsString('keys/notes.md:1:', $content, 'an ordinary file still matches');
        }
    }
}

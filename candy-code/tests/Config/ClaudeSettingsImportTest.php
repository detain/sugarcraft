<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\ClaudeSettingsImport;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Lang;

/**
 * C-TUI-5.b: the `candy-code import claude` translation table.
 *
 * Pins the design contract: `(prefix:*)` dialect translation, DENY WINNING
 * (with the vetoed allow still enumerated as a widening), every key with no
 * candy-code home reported as skipped, grammar-rejected patterns named rather
 * than guessed, hostile documents refused LOUD with nothing applied, and the
 * one write door merging onto the existing user-tier rows (idempotent on a
 * re-run, model routed through the per-provider `models` map).
 */
final class ClaudeSettingsImportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/cc-import-'
            . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $this->dir . '/' . $entry;
                @\unlink($path);
            }
        }
        @\rmdir($this->dir);
        Bootstrap::useConfigPath(null);
    }

    private function writeDoc(string $name, string $json): string
    {
        $path = $this->dir . '/' . $name;
        \file_put_contents($path, $json);

        return $path;
    }

    /** @param array<string, mixed> $doc */
    private function writeJsonDoc(string $name, array $doc): string
    {
        return $this->writeDoc($name, (string) \json_encode($doc));
    }

    public function testAbsentFilesAreSilentlyAbsent(): void
    {
        $report = ClaudeSettingsImport::inspect([$this->dir . '/nope.json']);

        $this->assertSame([], $report['files']);
        $this->assertSame([], $report['rules']);
        $this->assertNull($report['model']);
    }

    public function testDialectTranslationAndBucketOrder(): void
    {
        $a = $this->writeJsonDoc('a.json', ['permissions' => [
            'allow' => ['Bash(git:*)', 'Read'],
            'deny'  => ['Bash(rm:*)'],
            'ask'   => ['Write(//private/**)'],
        ]]);

        $report = ClaudeSettingsImport::inspect([$a]);

        $this->assertSame([$a], $report['files']);
        // Deny rows first, then ask, then allow — the first-match-wins launch
        // gate reads in order, so the translated precedence must be the order.
        $this->assertSame([
            ['pattern' => 'Bash(rm *)', 'action' => 'deny'],
            ['pattern' => 'Write(//private/**)', 'action' => 'ask'],
            ['pattern' => 'Bash(git *)', 'action' => 'allow'],
            ['pattern' => 'Read', 'action' => 'allow'],
        ], $report['rules']);
        // The `(x:*)` shape translated to `(x *)`; bare names pass through.
        $this->assertContains('Bash(git *)', $report['widenings']);
        $this->assertContains('Read', $report['widenings']);
        $this->assertSame([], $report['dropped']);
    }

    public function testDenyWinsAndTheVetoedAllowIsStillSeen(): void
    {
        $a = $this->writeJsonDoc('a.json', ['permissions' => [
            'allow' => ['Bash(git:*)'],
            'deny'  => ['Bash(git:*)', 'Bash(rm:*)'],
            'ask'   => ['Bash(rm:*)'],
        ]]);

        $report = ClaudeSettingsImport::inspect([$a]);

        $this->assertSame([
            ['pattern' => 'Bash(git *)', 'action' => 'deny'],
            ['pattern' => 'Bash(rm *)', 'action' => 'deny'],
        ], $report['rules']);
        // The one allow in the document was vetoed by the deny list...
        $this->assertSame(['Bash(git *)'], $report['dropped']);
        // ...and the vetoed widening is still enumerated for the preview —
        // the operator sees what the document ASKED to widen.
        $this->assertContains('Bash(git *)', $report['widenings']);
        // The rm pattern was never offered as an allow, so it widens nothing.
        $this->assertNotContains('Bash(rm *)', $report['widenings']);
    }

    public function testAskBeatsAllowAcrossDocumentsAndNeverLoosens(): void
    {
        $a = $this->writeJsonDoc('a.json', ['permissions' => ['ask' => ['Write']]]);
        $b = $this->writeJsonDoc('b.json', ['permissions' => ['allow' => ['Write']]]);

        $report = ClaudeSettingsImport::inspect([$a, $b]);

        $this->assertSame([['pattern' => 'Write', 'action' => 'ask']], $report['rules'],
            'a later allow must not loosen an earlier ask');
        // The allow is still counted as a widening the operator must see.
        $this->assertSame(['Write'], $report['dropped']);
    }

    public function testForeignKeysAreSkippedAndNamed(): void
    {
        $a = $this->writeJsonDoc('a.json', [
            'permissions'         => ['allow' => ['Read'], 'extra' => ['x']],
            'model'               => 'claude-sonnet-x',
            'hooks'               => ['PreToolUse' => []],
            'env'                 => ['A' => 'b'],
            'includeCoAuthoredBy' => false,
        ]);

        $report = ClaudeSettingsImport::inspect([$a]);

        $keys = [];
        foreach ($report['skipped'] as $row) {
            $keys[] = $row['key'];
        }
        $this->assertContains('permissions.extra', $keys);
        $this->assertContains('hooks', $keys);
        $this->assertContains('env', $keys);
        $this->assertContains('includeCoAuthoredBy', $keys);
        // The hooks row carries its own honest reason; the rest say "no home".
        foreach ($report['skipped'] as $row) {
            if ($row['key'] === 'hooks') {
                $this->assertSame(Lang::t('cli.sub.import.skipped_hooks'), $row['note']);
            } elseif ($row['key'] !== 'model') {
                $this->assertSame(Lang::t('cli.sub.import.skipped_nohome'), $row['note']);
            }
        }
        $this->assertSame('claude-sonnet-x', $report['model'], 'a usable model maps');
    }

    public function testUnusableModelIsSkippedNamed(): void
    {
        $a = $this->writeJsonDoc('a.json', ['model' => "bad\x01model"]);
        $b = $this->writeJsonDoc('b.json', ['model' => 42]);

        $report = ClaudeSettingsImport::inspect([$a, $b]);

        $this->assertNull($report['model']);
        $notes = [];
        foreach ($report['skipped'] as $row) {
            if ($row['key'] === 'model') {
                $notes[] = $row['note'];
            }
        }
        $this->assertCount(2, $notes);
        $this->assertSame(Lang::t('cli.sub.import.skipped_unusable'), $notes[0]);
    }

    public function testFirstUsableModelWins(): void
    {
        $a = $this->writeJsonDoc('a.json', ['model' => 'first']);
        $b = $this->writeJsonDoc('b.json', ['model' => 'second']);

        $this->assertSame('first', ClaudeSettingsImport::inspect([$a, $b])['model']);
    }

    public function testRejectedGrammarIsNamedNotGuessed(): void
    {
        // `Read(` — an unterminated paren is refused by the grammar gate.
        $a = $this->writeJsonDoc('a.json', ['permissions' => ['allow' => ['Read(', 'Read']]]);

        $report = ClaudeSettingsImport::inspect([$a]);

        $this->assertCount(1, $report['rejected']);
        $this->assertSame('Read(', $report['rejected'][0]['pattern']);
        $this->assertNotSame('', $report['rejected'][0]['reason']);
        $this->assertSame($a, $report['rejected'][0]['source']);
        // The good entry still lands; the bad one is never translated.
        $this->assertSame([['pattern' => 'Read', 'action' => 'allow']], $report['rules']);
    }

    public function testMalformedDocumentsRefuseLoudly(): void
    {
        $broken = $this->writeDoc('broken.json', '{"permissions": ');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        ClaudeSettingsImport::inspect([$broken]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function hostileDocumentCases(): array
    {
        return [
            'scalar root'     => ['"just a string"', '/not a settings object/'],
            'list root'       => ['["Read"]', '/not a settings object/'],
            'permissions not object' => ['{"permissions": "Read"}', '/permissions key is not an object/'],
            'list not array'  => ['{"permissions":{"allow":"Read"}}', '/is not a list of patterns/'],
            'non-string entry' => ['{"permissions":{"allow":[7]}}', '/non-string entry/'],
        ];
    }

    #[DataProvider('hostileDocumentCases')]
    public function testHostileDocumentsRefuse(string $json, string $message): void
    {
        $path = $this->writeDoc('hostile.json', $json);

        try {
            ClaudeSettingsImport::inspect([$path]);
            $this->fail('the import accepted a hostile document: ' . $json);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->assertMatchesRegularExpression($message, $e->getMessage());
        }
    }

    public function testSymlinkedFileIsRefused(): void
    {
        if (!@\symlink($this->writeJsonDoc('real.json', []), $this->dir . '/link.json')) {
            $this->markTestSkipped('symlinks unavailable on this platform');
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a plain readable file/');
        ClaudeSettingsImport::inspect([$this->dir . '/link.json']);
    }

    public function testOversizedFileIsRefusedUnread(): void
    {
        $path = $this->dir . '/big.json';
        \file_put_contents($path, '{"x":"' . \str_repeat('a', ClaudeSettingsImport::MAX_FILE_BYTES) . '"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/larger than/');
        ClaudeSettingsImport::inspect([$path]);
    }

    public function testPersistMergesDedupesAndKeepsUnrelatedKeys(): void
    {
        $configPath = $this->dir . '/config.json';
        \file_put_contents($configPath, (string) \json_encode([
            'provider'        => 'anthropic',
            'theme'           => 'dark',
            'permissionRules' => [['pattern' => 'Bash(ls *)', 'action' => 'allow']],
        ]));
        $writer = $this->writerFor($configPath);

        $report = ['rules' => [
            ['pattern' => 'Bash(rm *)', 'action' => 'deny'],
            ['pattern' => 'Bash(ls *)', 'action' => 'allow'],
        ], 'model' => null];

        $result = ClaudeSettingsImport::persist($report, $writer);

        $this->assertSame(1, $result['added'], 'the already-spelled rule was not re-added');
        $this->assertSame(2, $result['merged']);
        $this->assertSame($configPath, $result['path']);

        \clearstatcache();
        $after = (array) \json_decode((string) \file_get_contents($configPath), true);
        $this->assertSame('dark', $after['theme'], 'the writer preserves unrelated keys');
        $this->assertSame('anthropic', $after['provider']);
        $this->assertSame([
            ['pattern' => 'Bash(ls *)', 'action' => 'allow'],
            ['pattern' => 'Bash(rm *)', 'action' => 'deny'],
        ], $after['permissionRules'], 'imported rows land after the existing ones');

        // Re-running the same import is a no-op on the rules.
        $again = ClaudeSettingsImport::persist($report, $this->writerFor($configPath));
        $this->assertSame(0, $again['added']);
        $this->assertFalse($again['model']);
    }

    public function testPersistRoutesModelThroughThePerProviderMap(): void
    {
        $configPath = $this->dir . '/config.json';
        \file_put_contents($configPath, (string) \json_encode([
            'provider' => 'anthropic',
            'models'   => ['openai' => 'gpt-keep-me'],
        ]));
        Bootstrap::useConfigPath($configPath);

        $report = ['rules' => [], 'model' => 'claude-sonnet-x'];
        $result = ClaudeSettingsImport::persist($report, $this->writerFor($configPath), 'anthropic');

        $this->assertTrue($result['model']);
        \clearstatcache();
        $after = (array) \json_decode((string) \file_get_contents($configPath), true);
        $this->assertSame('claude-sonnet-x', $after['models']['anthropic'] ?? null);
        $this->assertSame('gpt-keep-me', $after['models']['openai'] ?? null,
            'another provider entry is never lost');
        $this->assertArrayNotHasKey('model', $after,
            'candy-code has no scalar model key; the import never invents one');
    }

    public function testPersistRefusesAModelWithoutAVettedProvider(): void
    {
        $configPath = $this->dir . '/config.json';
        \file_put_contents($configPath, '{}');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/without a vetted provider name/');
        ClaudeSettingsImport::persist(['rules' => [], 'model' => 'm'], $this->writerFor($configPath));
    }

    private function writerFor(string $configPath): SettingsWriter
    {
        return SettingsWriter::new($configPath, static function (array $set, array $unset) use ($configPath): void {
            $current = is_file($configPath)
                ? (array) (\json_decode((string) \file_get_contents($configPath), true) ?? [])
                : [];
            foreach ($unset as $key) {
                unset($current[$key]);
            }
            \file_put_contents($configPath, (string) \json_encode(array_merge($current, $set)));
        });
    }
}

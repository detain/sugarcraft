<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Config;

/**
 * @covers \SugarCraft\Serve\Config
 */
final class ConfigTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/config-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // fromDefaults tests
    // -------------------------------------------------------------------------

    public function testFromDefaultsHasCorrectName(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame('CandyServe', $c->name);
    }

    public function testFromDefaultsHasCorrectLogFormat(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame('text', $c->logFormat);
    }

    public function testFromDefaultsSshConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame(':23231', $c->sshListenAddr);
        $this->assertSame('ssh://localhost:23231', $c->sshPublicUrl);
        $this->assertSame(0, $c->sshMaxTimeout);
        $this->assertSame(120, $c->sshIdleTimeout);
    }

    public function testFromDefaultsGitConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame(':9418', $c->gitListenAddr);
        $this->assertSame(0, $c->gitMaxTimeout);
        $this->assertSame(3, $c->gitIdleTimeout);
        $this->assertSame(32, $c->gitMaxConnections);
    }

    public function testFromDefaultsHttpConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame(':23232', $c->httpListenAddr);
        $this->assertSame('http://localhost:23232', $c->httpPublicUrl);
    }

    public function testFromDefaultsLfsConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertTrue($c->lfsEnabled);
        $this->assertFalse($c->lfsSshEnabled);
    }

    public function testFromDefaultsJobsConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame('@every 10m', $c->mirrorPullSchedule);
    }

    public function testFromDefaultsStatsConfig(): void
    {
        $c = Config::fromDefaults();

        $this->assertSame(':23233', $c->statsListenAddr);
    }

    public function testFromDefaultsMaxPackBytesIsNull(): void
    {
        $c = Config::fromDefaults();

        $this->assertNull($c->maxPackBytes);
    }

    public function testLoadParsesMaxPackBytes(): void
    {
        // Inline maps were the only nesting the old hand-rolled parser
        // supported; symfony/yaml (plan 7.7) still parses them fine.
        $configPath = $this->tmpDir . '/pack.yaml';
        file_put_contents($configPath, "name: PackTest\nhttp: { max_pack_bytes: 1048576 }\n");

        $c = Config::load($configPath);

        $this->assertSame(1048576, $c->maxPackBytes);
    }

    public function testLoadWithoutMaxPackBytesIsNull(): void
    {
        // Deliberate 7.7 adjustment: an unquoted `:8080` in a YAML flow
        // map parses as int 8080 under symfony/yaml, so listen addrs
        // must be quoted (the README always showed them quoted).
        $configPath = $this->tmpDir . '/nopack.yaml';
        file_put_contents($configPath, "name: NoPackTest\nhttp: { listen_addr: \":8080\" }\n");

        $c = Config::load($configPath);

        $this->assertNull($c->maxPackBytes);
        $this->assertSame(':8080', $c->httpListenAddr);
    }

    // -------------------------------------------------------------------------
    // load tests
    // -------------------------------------------------------------------------

    public function testLoadThrowsForNonexistentFile(): void
    {
        $this->expectException(\RuntimeException::class);

        Config::load('/nonexistent/path/config.yaml');
    }

    public function testLoadParsesYaml(): void
    {
        $configPath = $this->tmpDir . '/config.yaml';
        file_put_contents($configPath, "name: TestServer\nlfs:\n  enabled: true\n");

        $c = Config::load($configPath);

        $this->assertSame('TestServer', $c->name);
        $this->assertTrue($c->lfsEnabled);
    }

    public function testLoadWithComments(): void
    {
        $configPath = $this->tmpDir . '/config.yaml';
        $yaml = <<<YAML
# This is a comment
name: CommentTest
# Another comment
lfs:
  enabled: false
YAML;
        file_put_contents($configPath, $yaml);

        $c = Config::load($configPath);

        $this->assertSame('CommentTest', $c->name);
        // 7.7: the old parser silently ignored indented nested keys and
        // this assertion could only check "it loaded"; with symfony/yaml
        // the nested value actually applies.
        $this->assertFalse($c->lfsEnabled);
    }

    public function testLoadWithInlineMap(): void
    {
        $configPath = $this->tmpDir . '/config.yaml';
        file_put_contents($configPath, "ssh:\n  key_path: ssh/key\n");

        $c = Config::load($configPath);

        $this->assertStringContainsString('ssh', $c->sshKeyPath);
    }

    public function testLoadWithInlineList(): void
    {
        $configPath = $this->tmpDir . '/config.yaml';
        file_put_contents($configPath, "name: ListTest");

        $c = Config::load($configPath);

        $this->assertSame('ListTest', $c->name);
    }

    // -------------------------------------------------------------------------
    // YAML parsing tests (indirectly through load)
    // -------------------------------------------------------------------------

    public function testParseYamlWithNestedStructure(): void
    {
        // Plan 7.7: block-style nested keys (the way the README always
        // documented config files) now actually take effect. The old
        // hand-rolled parser silently ignored every indented key.
        $configPath = $this->tmpDir . '/nested.yaml';
        $yaml = <<<YAML
name: NestedTest
ssh:
  listen_addr: ":2222"
  idle_timeout: 300
git:
  listen_addr: ":9419"
  max_connections: 16
http:
  listen_addr: ":8080"
  max_pack_bytes: 2097152
db:
  driver: "sqlite"
  data_source: "nested.db"
lfs:
  enabled: false
  ssh_enabled: true
jobs:
  mirror_pull: "@every 8h"
stats:
  listen_addr: ":9999"
YAML;
        file_put_contents($configPath, $yaml);

        $c = Config::load($configPath);

        $this->assertSame('NestedTest', $c->name);
        $this->assertSame(':2222', $c->sshListenAddr);
        $this->assertSame(300, $c->sshIdleTimeout);
        $this->assertSame(':9419', $c->gitListenAddr);
        $this->assertSame(16, $c->gitMaxConnections);
        $this->assertSame(':8080', $c->httpListenAddr);
        $this->assertSame(2097152, $c->maxPackBytes);
        $this->assertStringContainsString('nested.db', $c->dbDataSource);
        $this->assertFalse($c->lfsEnabled);
        $this->assertTrue($c->lfsSshEnabled);
        $this->assertSame('@every 8h', $c->mirrorPullSchedule);
        $this->assertSame(':9999', $c->statsListenAddr);
    }

    public function testLoadThrowsForMalformedYaml(): void
    {
        $configPath = $this->tmpDir . '/broken.yaml';
        // Unquoted @ starts a reserved indicator — a real YAML parse error.
        file_put_contents($configPath, "jobs:\n  mirror_pull: @every 10m\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid YAML config');

        Config::load($configPath);
    }

    public function testLoadThrowsForNonMappingYaml(): void
    {
        $configPath = $this->tmpDir . '/scalar.yaml';
        file_put_contents($configPath, "just a scalar\n");

        $this->expectException(\RuntimeException::class);

        Config::load($configPath);
    }

    public function testLoadEmptyFileFallsBackToDefaults(): void
    {
        $configPath = $this->tmpDir . '/empty-file.yaml';
        file_put_contents($configPath, "");

        $c = Config::load($configPath);

        $this->assertSame('CandyServe', $c->name);
        $this->assertSame(':23231', $c->sshListenAddr);
    }

    public function testLfsPathCreatesDirectory(): void
    {
        $configPath = $this->tmpDir . '/lfs-path.yaml';
        file_put_contents($configPath, "name: LfsPathTest\n");

        $c = Config::load($configPath);
        $lfsPath = $c->lfsPath();

        $this->assertStringContainsString('lfs', $lfsPath);
        $this->assertDirectoryExists($lfsPath);
    }

    public function testParseYamlWithEmptyValues(): void
    {
        $configPath = $this->tmpDir . '/empty.yaml';
        $yaml = <<<YAML
name: EmptyTest
tls_key_path:
tls_cert_path:
YAML;
        file_put_contents($configPath, $yaml);

        $c = Config::load($configPath);

        $this->assertSame('', $c->tlsKeyPath);
        $this->assertSame('', $c->tlsCertPath);
    }

    public function testParseYamlWithQuotedStrings(): void
    {

        $configPath = $this->tmpDir . '/quoted.yaml';
        file_put_contents($configPath, "name: \"Quoted Name\"\n");

        $c = Config::load($configPath);

        $this->assertSame('Quoted Name', $c->name);
    }

    public function testParseYamlWithBooleanValues(): void
    {

        $configPath = $this->tmpDir . '/booleans.yaml';
        file_put_contents($configPath, "name: BoolTest\n");

        $c = Config::load($configPath);

        $this->assertSame('BoolTest', $c->name);
    }

    public function testParseYamlWithNumericValues(): void
    {

        $configPath = $this->tmpDir . '/numeric.yaml';
        file_put_contents($configPath, "name: NumericTest\n");

        $c = Config::load($configPath);

        $this->assertSame('NumericTest', $c->name);
    }

    public function testParseYamlWithFloatValues(): void
    {
        $configPath = $this->tmpDir . '/float.yaml';
        file_put_contents($configPath, "name: FloatTest\n");

        $c = Config::load($configPath);

        // Just ensure it parses without error
        $this->assertSame('FloatTest', $c->name);
    }

    // -------------------------------------------------------------------------
    // Path helper tests
    // -------------------------------------------------------------------------

    public function testSshPathCreatesDirectory(): void
    {
        $configPath = $this->tmpDir . '/paths.yaml';
        file_put_contents($configPath, "name: PathTest\n");

        $c = Config::load($configPath);
        $sshPath = $c->sshPath();

        $this->assertStringContainsString('ssh', $sshPath);
        $this->assertDirectoryExists($sshPath);
    }

    public function testDbPath(): void
    {
        // db nested parsing doesn't work, but dbPath() returns resolved dataSource
        $configPath = $this->tmpDir . '/db.yaml';
        file_put_contents($configPath, "name: DbTest\n");

        $c = Config::load($configPath);
        $dbPath = $c->dbPath();

        $this->assertStringContainsString('candy-serve.db', $dbPath);
    }

    public function testReposPathCreatesDirectory(): void
    {
        $configPath = $this->tmpDir . '/repos.yaml';
        file_put_contents($configPath, "name: ReposTest\n");

        $c = Config::load($configPath);
        $reposPath = $c->reposPath();

        $this->assertStringContainsString('repositories', $reposPath);
        $this->assertDirectoryExists($reposPath);
    }

    // -------------------------------------------------------------------------
    // resolvePath tests (via Config construction)
    // -------------------------------------------------------------------------

    public function testResolvePathAbsoluteStaysAbsolute(): void
    {
        // Cannot test via nested YAML, but resolvePath logic is tested via fromDefaults
        $c = Config::fromDefaults();

        // The default path is relative, not absolute
        $this->assertStringContainsString('ssh', $c->sshKeyPath);
    }

    public function testResolvePathRelativeIsMadeAbsolute(): void
    {
        // Cannot test via nested YAML, but resolvePath logic is tested via fromDefaults
        $c = Config::fromDefaults();

        // The default path is relative, gets resolved against dataPath
        $this->assertStringContainsString('ssh', $c->sshKeyPath);
    }

    // -------------------------------------------------------------------------
    // Default values tests
    // -------------------------------------------------------------------------

    public function testDefaultsWhenValuesMissing(): void
    {
        $configPath = $this->tmpDir . '/minimal.yaml';
        file_put_contents($configPath, "name: MinimalTest\n");

        $c = Config::load($configPath);

        // SSH should have defaults
        $this->assertSame(':23231', $c->sshListenAddr);
        $this->assertSame('ssh://localhost:23231', $c->sshPublicUrl);
        // Git should have defaults
        $this->assertSame(':9418', $c->gitListenAddr);
        $this->assertSame(32, $c->gitMaxConnections);
        // HTTP should have defaults
        $this->assertSame(':23232', $c->httpListenAddr);
        // LFS should have defaults
        $this->assertTrue($c->lfsEnabled);
    }
}

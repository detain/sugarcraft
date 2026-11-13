<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * S22 (finding 039): the `user token` CLI refuses to mint past the
 * http.token_max_ttl ceiling — out loud, naming the knob — while the
 * honest short-TTL path keeps working. Real child processes, real
 * config.yaml on disk: this is the mint half of the dual enforcement
 * (the verify half lives in UserTrustTest).
 */
final class CliTokenTtlCapTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = \sys_get_temp_dir() . '/cgs-ttl-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
        parent::tearDown();
    }

    /** @param list<string> $args @return array{0:int,1:string,2:string} */
    private function runBin(array $args): array
    {
        $cmd  = \array_merge([\PHP_BINARY, \dirname(__DIR__) . '/bin/candy-git-server'], $args);
        $proc = \proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, \array_merge(\getenv(), ['CANDY_GIT_SERVER_LANG' => 'en']));
        \fclose($pipes[0]);
        $stdout = (string) \stream_get_contents($pipes[1]);
        $stderr = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $stdout, $stderr];
    }

    private function dataDir(string $httpYaml): string
    {
        $dir = $this->tmpDir . '/dp-' . \uniqid('', true);
        \mkdir($dir, 0700, true);
        \file_put_contents($dir . '/config.yaml', "http: { {$httpYaml} }\n");

        return $dir;
    }

    public function testMintingPastTheConfiguredCeilingRefusesNamingTheKnob(): void
    {
        $data = $this->dataDir('auth_secret: "s3cr3t", token_max_ttl: 60');

        [$rc, $stdout, $stderr] = $this->runBin(['user', 'token', 'alice', '3600', $data]);

        $this->assertNotSame(0, $rc, 'an over-ceiling TTL must not mint');
        $this->assertSame('', \trim($stdout));
        $this->assertStringContainsString('token_max_ttl', $stderr . $stdout, 'the refusal names the knob to raise');
        $this->assertStringContainsString('3600', $stderr . $stdout, 'the refusal quotes the offending TTL');
    }

    public function testMintingWithinTheCeilingSucceeds(): void
    {
        $data = $this->dataDir('auth_secret: "s3cr3t", token_max_ttl: 120');

        [$rc, $stdout] = $this->runBin(['user', 'token', 'alice', '60', $data]);

        $this->assertSame(0, $rc);
        $this->assertMatchesRegularExpression('/\d+\.[0-9a-f]{64}/', \trim($stdout), 'the wire shape is expiry.hmac');
    }

    public function testTheDefaultCeilingAdmitsAMonthLongLifeButRefusesImmortality(): void
    {
        $data = $this->dataDir('auth_secret: "s3cr3t"');

        [$rcOk] = $this->runBin(['user', 'token', 'alice', '86400', $data]);
        $this->assertSame(0, $rcOk, 'a day under the 30-day default mints fine');

        [$rcBad, , $stderr] = $this->runBin(['user', 'token', 'alice', '999999999999', $data]);
        $this->assertNotSame(0, $rcBad, 'the 039 reproducer TTL is refused by default');
        $this->assertStringContainsString('token_max_ttl', $stderr);
    }
}

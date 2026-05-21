<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\SshConfigParser;
use PHPUnit\Framework\TestCase;

final class SshConfigParserTest extends TestCase
{
    private function parse(string $raw): array
    {
        return (new SshConfigParser())->parse($raw);
    }

    public function testParseEmptyConfig(): void
    {
        $this->assertSame([], $this->parse(''));
    }

    public function testParseSingleHost(): void
    {
        $raw = <<<CONF
Host example
    HostName example.com
    User ubuntu
    Port 2222
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('example', $endpoints[0]->name);
        $this->assertSame('example.com', $endpoints[0]->host);
        $this->assertSame('ubuntu', $endpoints[0]->user);
        $this->assertSame(2222, $endpoints[0]->port);
    }

    public function testGlobalIdentityFileInherited(): void
    {
        $raw = <<<CONF
Host *
    IdentityFile ~/.ssh/id_ed25519

Host web
    HostName web.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertNotEmpty($endpoints[0]->identityFiles);
        $this->assertStringContainsString('id_ed25519', $endpoints[0]->identityFiles[0]);
        $this->assertStringStartsWith('/', $endpoints[0]->identityFiles[0]);
    }

    public function testGlobalProxyJumpInherited(): void
    {
        $raw = <<<CONF
Host *
    ProxyJump bastion.example.com

Host internal
    HostName internal.local
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('bastion.example.com', $endpoints[0]->proxyJump);
    }

    public function testGlobalUserInherited(): void
    {
        $raw = <<<CONF
Host *
    User default

Host server
    HostName server.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('default', $endpoints[0]->user);
    }

    public function testGlobalPortInherited(): void
    {
        $raw = <<<CONF
Host *
    Port 22022

Host backup
    HostName backup.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame(22022, $endpoints[0]->port);
    }

    public function testPerHostOverridesGlobal(): void
    {
        $raw = <<<CONF
Host *
    User globaluser
    Port 22022
    IdentityFile ~/.ssh/global_key

Host specific
    HostName specific.example.com
    User specificuser
    Port 2222
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('specificuser', $endpoints[0]->user);
        $this->assertSame(2222, $endpoints[0]->port);
        $this->assertSame([getenv('HOME') . '/.ssh/global_key'], $endpoints[0]->identityFiles);
    }

    public function testMultipleHosts(): void
    {
        $raw = <<<CONF
Host one
    HostName one.example.com

Host two
    HostName two.example.com

Host three
    HostName three.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(3, $endpoints);
        $this->assertSame('one', $endpoints[0]->name);
        $this->assertSame('two', $endpoints[1]->name);
        $this->assertSame('three', $endpoints[2]->name);
    }

    public function testHostPatternWithoutHostNameUsesPattern(): void
    {
        $raw = <<<CONF
Host backup-server
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('backup-server', $endpoints[0]->name);
        $this->assertSame('backup-server', $endpoints[0]->host);
        $this->assertSame(22, $endpoints[0]->port);
    }

    public function testMultipleIdentityFiles(): void
    {
        $raw = <<<CONF
Host multikey
    HostName multikey.example.com
    IdentityFile ~/.ssh/key1
    IdentityFile ~/.ssh/key2
    IdentityFile /etc/ssh/system_key
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertCount(3, $endpoints[0]->identityFiles);
        $this->assertStringContainsString('key1', $endpoints[0]->identityFiles[0]);
        $this->assertStringContainsString('key2', $endpoints[0]->identityFiles[1]);
        $this->assertSame('/etc/ssh/system_key', $endpoints[0]->identityFiles[2]);
    }

    public function testCommentsIgnored(): void
    {
        $raw = <<<CONF
# Global comment
Host *
    IdentityFile ~/.ssh/default  # inline comment

Host web  # web server
    HostName web.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame([getenv('HOME') . '/.ssh/default'], $endpoints[0]->identityFiles);
    }

    public function testEmptyLinesIgnored(): void
    {
        $raw = <<<CONF
Host a
    HostName a.example.com

Host b
    HostName b.example.com

CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(2, $endpoints);
    }

    public function testHostStarOnlyDoesNotCreateEndpoint(): void
    {
        $raw = <<<CONF
Host *
    IdentityFile ~/.ssh/default
    User global
CONF;
        $endpoints = $this->parse($raw);
        $this->assertSame([], $endpoints);
    }

    public function testMultipleHostsWithGlobalAndSpecificIdentityFiles(): void
    {
        $raw = <<<CONF
Host *
    IdentityFile ~/.ssh/default

Host prod
    HostName prod.example.com
    IdentityFile ~/.ssh/prod_key

Host staging
    HostName staging.example.com
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(2, $endpoints);
        $prod = $endpoints[0]->name === 'prod' ? $endpoints[0] : $endpoints[1];
        $staging = $endpoints[0]->name === 'staging' ? $endpoints[0] : $endpoints[1];

        $this->assertSame([getenv('HOME') . '/.ssh/prod_key'], $prod->identityFiles);
        $this->assertSame([getenv('HOME') . '/.ssh/default'], $staging->identityFiles);
    }

    public function testProxyJumpOverride(): void
    {
        $raw = <<<CONF
Host *
    ProxyJump global-bastion

Host specific
    HostName specific.example.com
    ProxyJump specific-bastion
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('specific-bastion', $endpoints[0]->proxyJump);
    }

    public function testOnlyHostNameOnHostLine(): void
    {
        $raw = <<<CONF
Host myhost
    HostName myhost.example.com
    User myuser
CONF;
        $endpoints = $this->parse($raw);
        $this->assertCount(1, $endpoints);
        $this->assertSame('myhost', $endpoints[0]->name);
        $this->assertSame('myhost.example.com', $endpoints[0]->host);
        $this->assertSame('myuser', $endpoints[0]->user);
    }
}

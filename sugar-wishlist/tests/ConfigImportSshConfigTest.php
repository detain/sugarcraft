<?php

declare(strict_types=1);

namespace SugarCraft\Wishlist\Tests;

use SugarCraft\Wishlist\Config;
use PHPUnit\Framework\TestCase;

final class ConfigImportSshConfigTest extends TestCase
{
    public function testImportFromMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::importFromSshConfig('/nonexistent/path/.ssh/config');
    }

    public function testImportFromEmptyFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config') . '_empty';
        file_put_contents($tmp, '');
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertSame([], $endpoints);
        } finally {
            unlink($tmp);
        }
    }

    public function testImportSingleEndpoint(): void
    {
        $raw = <<<CONF
Host web
    HostName web.example.com
    User www
    Port 2222
CONF;
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config');
        file_put_contents($tmp, $raw);
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertCount(1, $endpoints);
            $this->assertSame('web', $endpoints[0]->name);
            $this->assertSame('web.example.com', $endpoints[0]->host);
            $this->assertSame('www', $endpoints[0]->user);
            $this->assertSame(2222, $endpoints[0]->port);
        } finally {
            unlink($tmp);
        }
    }

    public function testImportGlobalIdentityFile(): void
    {
        $raw = <<<CONF
Host *
    IdentityFile ~/.ssh/id_ed25519

Host prod
    HostName prod.example.com
CONF;
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config');
        file_put_contents($tmp, $raw);
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertCount(1, $endpoints);
            $this->assertNotEmpty($endpoints[0]->identityFiles);
        } finally {
            unlink($tmp);
        }
    }

    public function testImportProxyJump(): void
    {
        $raw = <<<CONF
Host internal
    HostName internal.local
    ProxyJump bastion.example.com
CONF;
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config');
        file_put_contents($tmp, $raw);
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertCount(1, $endpoints);
            $this->assertSame('bastion.example.com', $endpoints[0]->proxyJump);
        } finally {
            unlink($tmp);
        }
    }

    public function testImportMultipleEndpoints(): void
    {
        $raw = <<<CONF
Host a
    HostName a.example.com

Host b
    HostName b.example.com
    User userb

Host c
    HostName c.example.com
    Port 2222
CONF;
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config');
        file_put_contents($tmp, $raw);
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertCount(3, $endpoints);
        } finally {
            unlink($tmp);
        }
    }

    public function testImportGlobalThenPerHost(): void
    {
        $raw = <<<CONF
Host *
    User globaluser
    IdentityFile ~/.ssh/global

Host specific
    HostName specific.example.com
    User localuser
CONF;
        $tmp = tempnam(sys_get_temp_dir(), 'ssh_config');
        file_put_contents($tmp, $raw);
        try {
            $endpoints = Config::importFromSshConfig($tmp);
            $this->assertCount(1, $endpoints);
            $this->assertSame('localuser', $endpoints[0]->user);
            $this->assertSame([getenv('HOME') . '/.ssh/global'], $endpoints[0]->identityFiles);
        } finally {
            unlink($tmp);
        }
    }
}

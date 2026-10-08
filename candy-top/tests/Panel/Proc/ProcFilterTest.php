<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\ContainerRef;
use SugarCraft\Top\Collect\VmInfo;
use SugarCraft\Top\Panel\Proc\ProcFilter;
use SugarCraft\Top\Tests\Support\ProcRows;

final class ProcFilterTest extends TestCase
{
    public function testPlainFilterIsACaseInsensitiveSubstringOfPidNameCmdUser(): void
    {
        $e = ProcRows::entry(4410, ['name' => 'node', 'cmd' => 'node /srv/Server.js', 'user' => 'www-data']);
        foreach (['441', 'NOD', 'server.JS', 'www', ''] as $hit) {
            $this->assertTrue(ProcFilter::matches($e, $hit), $hit);
        }
        foreach (['4411', 'python', 'root'] as $miss) {
            $this->assertFalse(ProcFilter::matches($e, $miss), $miss);
        }
    }

    public function testPlainFilterAlsoMatchesTheContainerOrVmName(): void
    {
        $vm = ProcRows::entry(6969, [
            'name' => 'qemu-system-x86',
            'container' => new ContainerRef('kvm', 'web01', 'web01', '/machine.slice/x', new VmInfo(guestName: 'web01')),
        ]);
        $this->assertTrue(ProcFilter::matches($vm, 'WEB0'));
        $ctr = ProcRows::entry(5, ['container' => new ContainerRef('docker', '3f2a1b9c0d1e', '3f2a1b9c0d1e', '/docker/x')]);
        $this->assertTrue(ProcFilter::matches($ctr, '3f2a'));
    }

    public function testRegexSearchesPidNameUserButFullMatchesTheCommand(): void
    {
        $e = ProcRows::entry(880, ['name' => 'postgres', 'cmd' => 'postgres -D /var/lib/pg', 'user' => 'postgres']);
        $this->assertTrue(ProcFilter::matches($e, '!^post'), 'name search');
        $this->assertTrue(ProcFilter::matches($e, '!8[0-9]'), 'pid search');
        $this->assertTrue(ProcFilter::matches($e, '!gres$'), 'user search');
        $this->assertTrue(ProcFilter::matches($e, '!.*-D /var/lib/pg'), 'cmd must match whole');
        $this->assertFalse(ProcFilter::matches($e, '!var/lib'), 'a partial cmd match alone does not count');
        $this->assertTrue(ProcFilter::matches($e, '!'), 'bare ! matches everything');
        $this->assertTrue(ProcFilter::matches($e, '!a~b|post'), 'the delimiter is escaped');
    }

    public function testTildeDelimiterKeepsTheUsersOwnEscapes(): void
    {
        $this->assertSame('a\\~b', ProcFilter::escapeDelimiter('a~b'));
        $this->assertSame('a\\~b', ProcFilter::escapeDelimiter('a\\~b'), 'already escaped: untouched');
        $this->assertSame('a\\\\\\~b', ProcFilter::escapeDelimiter('a\\\\~b'), 'escaped backslash, then a bare ~');
        $this->assertSame('[\\~]', ProcFilter::escapeDelimiter('[~]'), 'inside a class too');
        $e = ProcRows::entry(5, ['name' => 'a~b', 'cmd' => 'x', 'user' => 'u']);
        $this->assertTrue(ProcFilter::matches($e, '!a\\~b'), 'a user-escaped ~ still matches a literal ~');
        $this->assertTrue(ProcFilter::matches($e, '!a~b'));
        $this->assertTrue(ProcFilter::matches($e, '!^a[~]b$'));
        $slash = ProcRows::entry(6, ['name' => 'a\\~b', 'cmd' => 'x', 'user' => 'u']);
        $this->assertTrue(ProcFilter::matches($slash, '!a\\\\~b'), '\\\\ is a literal backslash before the ~');
    }

    public function testAnIncompleteRegexMatchesNothing(): void
    {
        $e = ProcRows::entry(1, ['name' => 'init(']);
        $this->assertFalse(ProcFilter::matches($e, '!init('));
        $this->assertFalse(ProcFilter::matches($e, '![unclosed'));
    }

    public function testContainerHiddenOnlyWithTheOptionAndAContainer(): void
    {
        $host = ProcRows::entry(1);
        $ctr = ProcRows::entry(2, ['container' => new ContainerRef('docker', 'id', 'id', '/x')]);
        $this->assertFalse(ProcFilter::containerHidden($ctr, false));
        $this->assertTrue(ProcFilter::containerHidden($ctr, true));
        $this->assertFalse(ProcFilter::containerHidden($host, true));
    }
}

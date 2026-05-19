<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests;

use SugarCraft\Wish\Session;
use PHPUnit\Framework\TestCase;

final class SessionMetadataTest extends TestCase
{
    public function testWithProtocolMetadataPopulatesAllFields(): void
    {
        $session = new Session(
            user:        'alice',
            clientHost:  '203.0.113.7',
            clientPort:  54321,
            serverHost: '198.51.100.4',
            serverPort:  22,
            term:        'xterm-256color',
            cols:        120,
            rows:        40,
            tty:         '/dev/pts/3',
            command:     null,
            lang:        'en_US.UTF-8',
        );

        $authenticated = $session->withProtocolMetadata(
            sessionId:      'a1b2c3d4e5f67890',
            authMethod:     'publickey',
            keyFingerprint: 'SHA256:abc123def456',
            clientVersion:  'SSH-2.0-OpenSSH_8.9',
            serverVersion:  'SSH-2.0-OpenSSH_9.6',
        );

        $this->assertSame('a1b2c3d4e5f67890', $authenticated->sessionId);
        $this->assertSame('publickey',          $authenticated->authMethod);
        $this->assertSame('SHA256:abc123def456', $authenticated->keyFingerprint);
        $this->assertSame('SSH-2.0-OpenSSH_8.9', $authenticated->clientVersion);
        $this->assertSame('SSH-2.0-OpenSSH_9.6', $authenticated->serverVersion);
    }

    public function testWithProtocolMetadataPreservesExistingFields(): void
    {
        $session = new Session(
            user:        'bob',
            clientHost:  '198.51.100.10',
            clientPort:  11111,
            serverHost:  '203.0.113.1',
            serverPort:  22,
            term:        'tmux-256color',
            cols:        100,
            rows:        30,
            tty:         '/dev/pts/0',
            command:     'wishlist',
            lang:        'C.UTF-8',
        );

        $authenticated = $session->withProtocolMetadata(
            sessionId:     'xyz789',
            authMethod:    'password',
            keyFingerprint: null,
            clientVersion: 'SSH-2.0-PHP_candy-wish',
            serverVersion: 'SSH-2.0-OpenSSH_9.6',
        );

        $this->assertSame('bob',                $authenticated->user);
        $this->assertSame('198.51.100.10',      $authenticated->clientHost);
        $this->assertSame(11111,                $authenticated->clientPort);
        $this->assertSame('203.0.113.1',        $authenticated->serverHost);
        $this->assertSame(22,                   $authenticated->serverPort);
        $this->assertSame('tmux-256color',      $authenticated->term);
        $this->assertSame(100,                  $authenticated->cols);
        $this->assertSame(30,                   $authenticated->rows);
        $this->assertSame('/dev/pts/0',         $authenticated->tty);
        $this->assertSame('wishlist',           $authenticated->command);
        $this->assertSame('C.UTF-8',            $authenticated->lang);
    }

    public function testWithProtocolMetadataReturnsNewInstance(): void
    {
        $session = new Session(
            user:        'carol',
            clientHost:  '203.0.113.99',
            clientPort:  5555,
            serverHost:  '198.51.100.1',
            serverPort:  22,
            term:        'xterm-256color',
            cols:        80,
            rows:        24,
            tty:         null,
            command:     null,
            lang:        'en_US.UTF-8',
        );

        $authenticated = $session->withProtocolMetadata(
            sessionId:     'new-session-id',
            authMethod:    'publickey',
            keyFingerprint: 'SHA256:xyz789',
            clientVersion: 'SSH-2.0-OpenSSH_8.9',
            serverVersion: 'SSH-2.0-OpenSSH_9.6',
        );

        $this->assertNotSame($session, $authenticated);
        $this->assertNull($session->sessionId);
        $this->assertSame('new-session-id', $authenticated->sessionId);
    }

    public function testSessionMetadataIsNullBeforeHandshake(): void
    {
        $session = new Session(
            user:        'dave',
            clientHost:  '203.0.113.50',
            clientPort:  33333,
            serverHost:  '198.51.100.5',
            serverPort:  22,
            term:        'xterm-256color',
            cols:        80,
            rows:        24,
            tty:         null,
            command:     null,
            lang:        'C.UTF-8',
        );

        $this->assertNull($session->sessionId);
        $this->assertNull($session->authMethod);
        $this->assertNull($session->keyFingerprint);
        $this->assertNull($session->clientVersion);
        $this->assertNull($session->serverVersion);
    }
}

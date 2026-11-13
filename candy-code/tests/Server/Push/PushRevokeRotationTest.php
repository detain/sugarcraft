<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Server\Push;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\Server\Auth\AuthContext;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\Http\StaticFiles;
use SugarCraft\Code\Server\Push\PushSubscription;
use SugarCraft\Code\Server\Push\PushSubscriptionStore;
use SugarCraft\Code\Server\Server;
use SugarCraft\Code\Server\ServerConfig;

/**
 * A token rotation retires every credential class, so the push rows those
 * classes owned must go with it (S-O14): N rotations leave a bounded row
 * count instead of leaking one row per sign-in forever.
 */
final class PushRevokeRotationTest extends TestCase
{
    private const RFC_POINT = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';

    private const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/serve-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $left) {
            @\unlink($left);
        }
        @\rmdir($this->dir);
    }

    public function testRowsDoNotSurviveRotationsAndStayBounded(): void
    {
        $tokens = TokenStore::new($this->dir);
        $tokens->token();
        $auth = AuthContext::new($tokens);
        $server = Server::new(ServerConfig::new($this->dir)->withPort(0), $auth, StaticFiles::new(null), null, Loop::get());
        $server->reloadToken();

        $held = PushSubscriptionStore::forStateDir($this->dir);
        for ($i = 1; $i <= 5; $i++) {
            self::assertTrue($held->put(
                PushSubscription::new('https://updates.push.services.mozilla.com/wpush/v2/tok' . $i, self::RFC_POINT, self::RFC_AUTH),
                $i % 2 === 0 ? 'bearer' : 'cookie',
                false,
            ));
            TokenStore::new($this->dir)->rotate();
            self::assertSame(0, $server->reloadToken());
            self::assertSame([], PushSubscriptionStore::forStateDir($this->dir)->entries(), "rotation $i left a row behind");
        }

        // A long-lived store instance must not write its stale cache back.
        self::assertTrue($held->put(
            PushSubscription::new('https://updates.push.services.mozilla.com/wpush/v2/after', self::RFC_POINT, self::RFC_AUTH),
            'bearer',
            false,
        ));
        self::assertCount(1, PushSubscriptionStore::forStateDir($this->dir)->entries());
    }
}

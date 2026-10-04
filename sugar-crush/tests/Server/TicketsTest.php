<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Server;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Server\Auth\CookieSessions;
use SugarCraft\Crush\Server\Auth\ExpiringSecrets;
use SugarCraft\Crush\Server\Auth\LoginCodes;
use SugarCraft\Crush\Server\Auth\RateLimiter;
use SugarCraft\Crush\Server\Auth\Tickets;
use SugarCraft\Crush\Server\Auth\TokenStore;

/**
 * The credential stores behind Appendix O §8.2: single-use tickets and login
 * codes, sliding cookie sessions, the failure throttle and the 0600 token file.
 */
final class TicketsTest extends TestCase
{
    private float $now = 5000.0;

    private ?string $stateRoot = null;

    protected function tearDown(): void
    {
        if ($this->stateRoot === null) {
            return;
        }
        foreach (\glob($this->stateRoot . '/*') ?: [] as $entry) {
            if (\is_link($entry)) {
                \unlink($entry);
                continue;
            }
            \chmod($entry, 0o700);
            foreach (\glob($entry . '/*') ?: [] as $file) {
                \unlink($file);
            }
            \rmdir($entry);
        }
        \rmdir($this->stateRoot);
    }

    private function clock(): \Closure
    {
        return fn (): float => $this->now;
    }

    public function testATicketIsSingleUseBoundAndShortLived(): void
    {
        $tickets = Tickets::new($this->clock());
        $ticket = $tickets->mint('session-a');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $ticket);
        self::assertSame('session-a', $tickets->consume($ticket));
        self::assertNull($tickets->consume($ticket), 'spent');

        $late = $tickets->mint('session-a');
        $this->now += Tickets::TTL_SECONDS;
        self::assertNull($tickets->consume($late), 'expired at exactly the TTL');
        self::assertNull($tickets->consume(''));
        self::assertSame(0, $tickets->pending());
    }

    public function testALoginCodeIsSpentByItsFirstUseRightOrWrong(): void
    {
        $codes = LoginCodes::new($this->clock());
        $code = $codes->mint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $code);
        self::assertSame(1, $codes->pending());
        self::assertFalse($codes->consume('not-' . $code));
        self::assertTrue($codes->consume($code));
        self::assertFalse($codes->consume($code));

        $stale = $codes->mint();
        $this->now += LoginCodes::TTL_SECONDS + 1;
        self::assertFalse($codes->consume($stale));
    }

    public function testACookieSessionSlidesOnUseAndLapsesWhenIdle(): void
    {
        $sessions = CookieSessions::new($this->clock());
        $id = $sessions->open();

        $this->now += CookieSessions::LIFETIME_SECONDS - 10;
        self::assertTrue($sessions->validate($id), 'used just before it lapses');
        $this->now += CookieSessions::LIFETIME_SECONDS - 10;
        self::assertTrue($sessions->validate($id), 'the use above slid it');
        $this->now += CookieSessions::LIFETIME_SECONDS;
        self::assertFalse($sessions->validate($id), 'idle a full lifetime');

        $revoked = $sessions->open();
        $sessions->revoke($revoked);
        self::assertFalse($sessions->validate($revoked));
        self::assertFalse($sessions->validate(''));
    }

    public function testSecretsAreHeldOnlyAsHashes(): void
    {
        $secrets = ExpiringSecrets::new(60, $this->clock());
        $secret = $secrets->mint('bound');

        self::assertStringNotContainsString($secret, \var_export($secrets, true));
        self::assertSame('bound', $secrets->peek($secret));
        self::assertSame(1, $secrets->count());
    }

    public function testTheSecretMapIsBoundedOldestExpiryFirst(): void
    {
        $secrets = ExpiringSecrets::new(60, $this->clock(), 3);
        $first = $secrets->mint('1');
        $this->now += 1;
        $secrets->mint('2');
        $secrets->mint('3');
        $secrets->mint('4');

        self::assertSame(3, $secrets->count());
        self::assertNull($secrets->peek($first), 'the soonest-expiring secret made room');
    }

    public function testTheLimiterLocksAnAddressOutAfterTenFailuresInAMinute(): void
    {
        $limiter = RateLimiter::new($this->clock());
        for ($i = 0; $i < RateLimiter::MAX_FAILURES - 1; ++$i) {
            $limiter->recordFailure('10.0.0.1');
        }
        self::assertSame(0, $limiter->retryAfter('10.0.0.1'));

        $limiter->recordFailure('10.0.0.1');
        self::assertSame(RateLimiter::LOCKOUT_SECONDS, $limiter->retryAfter('10.0.0.1'));
        self::assertSame(0, $limiter->retryAfter('10.0.0.2'));

        $this->now += RateLimiter::LOCKOUT_SECONDS;
        self::assertSame(0, $limiter->retryAfter('10.0.0.1'));
    }

    public function testFailuresOutsideTheWindowDoNotAccumulate(): void
    {
        $limiter = RateLimiter::new($this->clock());
        for ($i = 0; $i < 3 * RateLimiter::MAX_FAILURES; ++$i) {
            $limiter->recordFailure('10.0.0.1');
            $this->now += RateLimiter::WINDOW_SECONDS / (RateLimiter::MAX_FAILURES - 1);
        }

        self::assertSame(0, $limiter->retryAfter('10.0.0.1'));
    }

    public function testTheTokenFileIsMintedOnce0600InAPrivateDirectory(): void
    {
        $dir = $this->stateDir();
        $store = TokenStore::new($dir);
        $token = $store->token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertSame(0o700, \fileperms($dir) & 0o777);
        self::assertSame(0o600, \fileperms($dir . '/' . TokenStore::FILE) & 0o777);
        self::assertSame($token, TokenStore::new($dir)->token(), 'a second store reads the same token');
        self::assertTrue($store->matches($token));
        self::assertFalse($store->matches(''));
        self::assertFalse($store->matches(\strrev($token)));

        $rotated = $store->rotate();
        self::assertNotSame($token, $rotated);
        self::assertSame($rotated, TokenStore::new($dir)->token());
    }

    public function testALooseOrLinkedStateDirectoryIsRefused(): void
    {
        $dir = $this->stateDir();
        \mkdir($dir, 0o755);
        \chmod($dir, 0o755);

        try {
            TokenStore::new($dir)->token();
            self::fail('a group/world-readable state directory was accepted');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('server state', $e->getMessage());
        }

        $link = $this->stateDir();
        \chmod($dir, 0o700);
        \symlink($dir, $link);
        $this->expectException(\RuntimeException::class);
        TokenStore::new($link)->token();
    }

    public function testAnOverrideReplacesTheFileAndMustBeLongEnough(): void
    {
        $store = TokenStore::new($this->stateDir())->withOverride(\str_repeat('k', 40));

        self::assertSame(\str_repeat('k', 40), $store->token());
        self::assertTrue($store->matches(\str_repeat('k', 40)));
        self::assertSame(\str_repeat('k', 40), $store->withOverride('  ')->withOverride(\str_repeat('k', 40))->token());

        $this->expectException(\InvalidArgumentException::class);
        TokenStore::new($this->stateDir())->withOverride('short');
    }

    private function stateDir(): string
    {
        $this->stateRoot ??= \sys_get_temp_dir() . '/sc-srv-' . \bin2hex(\random_bytes(4));
        if (!\is_dir($this->stateRoot)) {
            \mkdir($this->stateRoot, 0o700);
        }

        return $this->stateRoot . '/s' . \bin2hex(\random_bytes(3));
    }
}

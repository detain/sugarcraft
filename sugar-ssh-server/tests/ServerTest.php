<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Middleware;
use SugarCraft\SshServer\Middleware\Keepalive;
use SugarCraft\SshServer\Server;
use SugarCraft\SshServer\Session;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testStackInvokedInRegistrationOrder(): void
    {
        $log = [];
        $mw1 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'a-pre';
                $next($ctx, $s);
                $this->log[] = 'a-post';
            }
        };
        $mw2 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'b-pre';
                $next($ctx, $s);
                $this->log[] = 'b-post';
            }
        };
        $mw3 = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'c';
            }
        };

        Server::new()->use($mw1)->use($mw2)->use($mw3)->serve($this->fakeSession());

        $this->assertSame(['a-pre', 'b-pre', 'c', 'b-post', 'a-post'], $log);
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $log = [];
        $gate = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'gate-blocked';
            }
        };
        $never = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'reached';
            }
        };
        Server::new()->use($gate)->use($never)->serve($this->fakeSession());
        $this->assertSame(['gate-blocked'], $log);
    }

    public function testEmptyStackIsNoop(): void
    {
        $this->assertSame(0, Server::new()->serve($this->fakeSession()));
    }

    public function testServeReturnsTheTransportExitStatus(): void
    {
        // Seam pin: serve() hands the transport's status to
        // the ForceCommand script, which exits with it so the SSH
        // client sees the child's real failure code.
        $transport = new class implements \SugarCraft\SshServer\Transport {
            public function run(Context $ctx, Session $session, array $stack): int
            {
                return 42;
            }
        };

        $server = Server::new()->withTransport($transport);

        $this->assertSame(42, $server->serve($this->fakeSession()));
    }

    public function testDefaultTransportIsInProcess(): void
    {
        $server = Server::new();
        $this->assertInstanceOf(
            \SugarCraft\SshServer\Transport\InProcessTransport::class,
            $server->transport(),
        );
    }

    public function testWithTransportOverridesDefault(): void
    {
        $hostSshd = new \SugarCraft\SshServer\Transport\HostSshdTransport();
        $server = Server::new()->withTransport($hostSshd);
        $this->assertSame($hostSshd, $server->transport());
    }

    public function testServeDelegatesToActiveTransport(): void
    {
        $log = [];
        $captured = null;
        $captured2 = null;
        $capturedStack = null;
        $transport = new class($log, $captured, $captured2, $capturedStack) implements \SugarCraft\SshServer\Transport {
            public function __construct(
                private array &$log,
                private ?Context &$ctx,
                private ?Session &$session,
                private ?array &$stack,
            ) {}
            public function run(Context $ctx, Session $session, array $stack): int
            {
                $this->log[] = 'transport-run';
                $this->ctx = $ctx;
                $this->session = $session;
                $this->stack = $stack;
                return 0;
            }
        };

        $mw = new class($log) implements Middleware {
            public function __construct(private array &$log) {}
            public function handle(Context $ctx, Session $s, callable $next): void
            {
                $this->log[] = 'mw';
            }
        };

        $session = $this->fakeSession();
        Server::new()
            ->withTransport($transport)
            ->use($mw)
            ->serve($session);

        $this->assertSame(['transport-run'], $log, 'transport must run; mw runs only if transport invokes it');
        $this->assertInstanceOf(Context::class, $captured);
        $this->assertSame($session, $captured2);
    }

    public function testWithKeepaliveReturnsANewInstance(): void
    {
        // The with* builders clone: the receiver — the receiver
        // keeps its own stack untouched, so a shared base Server can
        // be branched per route.
        $server = Server::new();
        $result = $server->withKeepalive();

        $this->assertNotSame($server, $result);

        $originalStack = (function (): array {
            return $this->stack;
        })->call($server);
        $this->assertCount(0, $originalStack);
    }

    public function testWithTransportReturnsANewInstance(): void
    {
        $server = Server::new();
        $hostSshd = new \SugarCraft\SshServer\Transport\HostSshdTransport();
        $result = $server->withTransport($hostSshd);

        $this->assertNotSame($server, $result);
        $this->assertInstanceOf(
            \SugarCraft\SshServer\Transport\InProcessTransport::class,
            $server->transport(),
            'the receiver must keep its default transport',
        );
        $this->assertSame($hostSshd, $result->transport());
    }

    public function testServeThreadsTheInjectedContext(): void
    {
        // Seam pin: serve() exposes a Context injection point, so
        // the M1 cancel-propagation machinery is reachable through the
        // public API instead of only via transport->run() directly.
        $captured = null;
        $recorder = new class ($captured) implements \SugarCraft\SshServer\Middleware {
            public function __construct(private mixed &$captured)
            {
            }

            public function handle(Context $ctx, Session $session, callable $next)
            {
                $this->captured = $ctx;
                return null;
            }
        };

        $transport = new class implements \SugarCraft\SshServer\Transport {
            public function run(Context $ctx, Session $session, array $stack): int
            {
                foreach ($stack as $mw) {
                    $mw->handle($ctx, $session, static fn () => null);
                }
                return 0;
            }
        };

        $ctx = Context::background();
        $server = Server::new()->withTransport($transport)->use($recorder);
        $server->serve($this->fakeSession(), $ctx);

        $this->assertSame($ctx, $captured);
    }

    public function testWithKeepaliveAppendsKeepaliveMiddleware(): void
    {
        $server = Server::new()->withKeepalive();

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertCount(1, $stack);
        $this->assertInstanceOf(Keepalive::class, $stack[0]);
    }

    public function testWithKeepaliveDefaultIntervalIs60(): void
    {
        $server = Server::new()->withKeepalive();

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertSame(60, $stack[0]->interval());
    }

    public function testWithKeepaliveCustomInterval(): void
    {
        $server = Server::new()->withKeepalive(30);

        $stack = (function (): array {
            return $this->stack;
        })->call($server);

        $this->assertSame(30, $stack[0]->interval());
    }

    /**
     * `use()` is the documented mutating call on this class (audit MINOR 7),
     * and the consequence is what the docblock teaches: an alias and its
     * receiver share one stack. Pinned rather than merely described, because
     * every README example chains off one receiver and a silent switch to
     * clone-on-append would leave those examples working while breaking the
     * assumption behind them.
     */
    public function testAppendingThroughAnAliasReachesTheOriginalStack(): void
    {
        $log = [];
        $marker = static function () use (&$log): Middleware {
            return new class($log) implements Middleware {
                /** @param list<string> $log */
                public function __construct(private array &$log) {}

                public function handle(Context $ctx, Session $session, callable $next): void
                {
                    $this->log[] = 'hit';
                    $next($ctx, $session);
                }
            };
        };

        $original = Server::new()->use($marker());
        $alias = $original->use($marker());

        $this->assertSame(
            $original,
            $alias,
            'use() returns the receiver: that mutation is the documented contract',
        );

        $original->serve($this->fakeSession());

        $this->assertCount(2, $log, 'both middlewares run for a server reached through the original receiver');
    }

    /**
     * ssrv-10: `withLocale()` is the `withTransport()` polarity — the
     * receiver it was called off stays unarmed, the clone arms its own
     * `serve()` entries.
     */
    public function testWithLocaleReturnsANewInstanceAndLeavesTheReceiverUnarmed(): void
    {
        $prev = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');
        $observed = [];
        $transport = new class ($observed) implements \SugarCraft\SshServer\Transport {
            /** @param array<string,string> $observed */
            public function __construct(private array &$observed) {}

            public function run(Context $ctx, Session $session, array $stack): int
            {
                $this->observed['locale'] = \SugarCraft\Core\I18n\T::locale();

                return 0;
            }
        };

        try {
            $server = Server::new()->withTransport($transport);
            $armed = $server->withLocale('de');
            $this->assertNotSame($server, $armed);

            $server->serve($this->fakeSession());
            $this->assertSame('en', $observed['locale'], 'the receiver of withLocale() stays unarmed');

            $armed->serve($this->fakeSession());
            $this->assertSame('de', $observed['locale'], 'the armed clone stamps the locale at serve() entry');
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prev);
        }
    }

    /**
     * ssrv-10: an armed server renders a known catalogue key in German
     * through the canned notice path, right where the transport runs.
     */
    public function testArmedLocaleRendersTheCatalogueDuringServe(): void
    {
        $prev = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('en');

        try {
            $observed = [];
            $transport = new class ($observed) implements \SugarCraft\SshServer\Transport {
                /** @param array<string,string> $observed */
                public function __construct(private array &$observed) {}

                public function run(Context $ctx, Session $session, array $stack): int
                {
                    $this->observed['notice'] = \SugarCraft\SshServer\Lang::t('auth.reject_user', ['name' => 'alice']);

                    return 0;
                }
            };

            Server::new()->withTransport($transport)->withLocale('de')->serve($this->fakeSession());

            $this->assertSame(
                'Benutzer nicht erlaubt: alice',
                $observed['notice'],
                'armed serve() entries render catalogue keys in the armed language',
            );
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prev);
        }
    }

    /**
     * ssrv-10: when unarmed, the default is hands-off — an entry script
     * that ran `T::setLocale()` itself keeps its choice across an
     * unarmed `serve()`, and the arm adds zero call sites (invisible-
     * by-default fixed by opt-in, not by an implicit pick).
     */
    public function testAnUnarmedServeLeavesTheGlobalLocaleAlone(): void
    {
        $prev = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::setLocale('fr');

        try {
            $observed = [];
            $transport = new class ($observed) implements \SugarCraft\SshServer\Transport {
                /** @param array<string,string> $observed */
                public function __construct(private array &$observed) {}

                public function run(Context $ctx, Session $session, array $stack): int
                {
                    $this->observed['locale'] = \SugarCraft\Core\I18n\T::locale();

                    return 0;
                }
            };

            Server::new()->withTransport($transport)->serve($this->fakeSession());

            $this->assertSame('fr', $observed['locale'], 'an unarmed serve() must not restamp mid-run');
            $this->assertSame('fr', \SugarCraft\Core\I18n\T::locale(), 'an unarmed serve() adds zero T::setLocale calls');
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prev);
        }
    }
}

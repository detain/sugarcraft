<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Core\Concerns\Mutable;

/**
 * App's with*() builders ride the shared {@see Mutable} splat rather than a
 * hand-written 67-argument constructor call. These pins hold the behaviour the
 * splat must keep: unrelated fields carry across a wither, an explicit null
 * resets a nullable field, and the rebuilt App is a new instance.
 *
 * @see App::withError()
 * @see App::withStatus()
 */
final class AppMutateSplatTest extends TestCase
{
    private ProviderInterface $provider;

    protected function setUp(): void
    {
        $this->provider = $this->createMock(ProviderInterface::class);
    }

    public function testAppUsesTheSharedMutableSplatAndDefinesNoOwnMutate(): void
    {
        $class = new \ReflectionClass(App::class);

        self::assertContains(Mutable::class, $class->getTraitNames());
        $source = (string) file_get_contents((string) $class->getFileName());
        self::assertDoesNotMatchRegularExpression('/function\s+mutate\s*\(/', $source);
    }

    public function testWitherReturnsANewInstanceCarryingEveryOtherField(): void
    {
        $base = App::new($this->provider, 'gpt-4')->withStatus('busy')->withError('boom');
        $next = $base->withModel('claude-3-opus');

        self::assertNotSame($base, $next);
        self::assertSame('claude-3-opus', $next->model);
        self::assertSame('busy', $next->status);
        self::assertSame('boom', $next->error);
        self::assertSame($this->provider, $next->provider);
        self::assertSame($base->messages, $next->messages);
    }

    public function testExplicitNullResetsANullableFieldWithoutTouchingOthers(): void
    {
        $failed = App::new($this->provider, 'gpt-4')->withStatus('busy')->withError('boom');
        $cleared = $failed->withError(null);

        self::assertNull($cleared->error);
        self::assertSame('busy', $cleared->status);
        self::assertSame('gpt-4', $cleared->model);
    }

    public function testChainedWithersComposeWithoutDroppingEarlierChanges(): void
    {
        $app = App::new($this->provider, 'gpt-4')
            ->withSessionId('sess-1')
            ->withReasoningEffort('high')
            ->withSelectedAgentIndex(3);

        self::assertSame('sess-1', $app->sessionId);
        self::assertSame('high', $app->reasoningEffort);
        self::assertSame(3, $app->selectedAgentIndex);
    }
}

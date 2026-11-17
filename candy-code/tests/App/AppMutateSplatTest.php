<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\App;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Core\Concerns\Mutable;

/**
 * App's with*() builders ride the shared {@see Mutable} splat rather than a
 * hand-written 67-argument constructor call. These pins hold the behavior the
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

        foreach ((new \ReflectionClass(App::class))->getConstructor()->getParameters() as $param) {
            $name = $param->getName();
            if ($name === 'model') {
                continue;
            }
            $prop = new \ReflectionProperty(App::class, $name);
            $before = $prop->getValue($base);
            $after = $prop->getValue($next);
            if (is_object($before)) {
                self::assertSame($before, $after, "field `$name` did not carry across the wither");
            } else {
                self::assertEquals($before, $after, "field `$name` did not carry across the wither");
            }
        }
    }

    public function testMutateIsProtectedAndTheTraitDeclaresItProtected(): void
    {
        self::assertTrue((new \ReflectionMethod(App::class, 'mutate'))->isProtected());
        self::assertTrue((new \ReflectionMethod(Mutable::class, 'mutate'))->isProtected());
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

    /**
     * Declared behavior change: the old variadic mutate() silently dropped a
     * key it did not know, while the shared splat forwards every key as a
     * named constructor argument, so an unknown key fails loudly as an Error.
     */
    public function testAnUnknownChangeKeyFailsLoudlyInsteadOfBeingDropped(): void
    {
        $app = App::new($this->provider, 'gpt-4');
        $mutate = new \ReflectionMethod(App::class, 'mutate');

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown named parameter');

        $mutate->invoke($app, ['noSuchField' => 1]);
    }
}

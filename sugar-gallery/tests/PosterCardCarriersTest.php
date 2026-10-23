<?php

declare(strict_types=1);

namespace SugarCraft\Gallery\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Gallery\PosterCard;

/**
 * The single-carrier law (docs-review-1023 m6): before the fix, five
 * hand-rolled positional `new self(...)` reconstructions each had to remember
 * all eight constructor fields — a ninth field added to the constructor would
 * have been silently dropped by every one of them. Every with*-mutator now
 * flows through the one private carrying(): full state snapshot + named
 * overrides + constructor rebuild.
 *
 * These pins are the discriminating set: a re-spreading carrier that forgets
 * a field, or a re-introduced hand-rolled new self(...), goes red here.
 */
final class PosterCardCarriersTest extends TestCase
{
    private function fullCard(): PosterCard
    {
        return new PosterCard('7', 'Title', 'https://x/y', 0.5, 'inline-art', "\e[1mStyled", 'sixel-bytes', 42);
    }

    /** A type-appropriate sentinel value for the named constructor field. */
    private static function sentinelFor(string $name): mixed
    {
        return match ($name) {
            'progress' => 0.25,
            'imageId' => 99,
            default => 'SENTINEL-' . $name,
        };
    }

    public function testCarryingRebuildsEverySingleConstructorField(): void
    {
        $card = $this->fullCard();
        $carrying = new ReflectionMethod(PosterCard::class, 'carrying');
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new ReflectionClass(PosterCard::class))->getConstructor()->getParameters(),
        );

        foreach ($params as $name) {
            $result = $carrying->invoke($card, [$name => self::sentinelFor($name)]);

            self::assertSame(
                self::sentinelFor($name),
                $result->$name,
                "carrying() must apply the override for \$$name",
            );

            foreach ($params as $other) {
                if ($other !== $name) {
                    self::assertSame(
                        $card->$other,
                        $result->$other,
                        "carrying(['$name' => …]) must preserve \$$other",
                    );
                }
            }
        }
    }

    public function testAnUnknownOverrideNameFailsLoud(): void
    {
        $carrying = new ReflectionMethod(PosterCard::class, 'carrying');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a constructor field');

        $carrying->invoke($this->fullCard(), ['posterWidth' => 10]);
    }

    public function testEveryDocumentedCarrierPreservesAllOtherFields(): void
    {
        $card = $this->fullCard();

        $poster = $card->withPoster('new-art');
        self::assertSame('new-art', $poster->poster);
        self::assertSame(['7', 'Title', 'https://x/y', 0.5, "\e[1mStyled", 'sixel-bytes', 42],
            [$poster->id, $poster->title, $poster->posterUrl, $poster->progress, $poster->styledTitle, $poster->posterImage, $poster->imageId]);

        $image = $card->withImage('kitty-bytes', 8);
        self::assertSame('kitty-bytes', $image->posterImage);
        self::assertSame(8, $image->imageId);
        self::assertSame(['7', 'Title', 'https://x/y', 0.5, 'inline-art', "\e[1mStyled"],
            [$image->id, $image->title, $image->posterUrl, $image->progress, $image->poster, $image->styledTitle]);

        $progress = $card->withProgress(0.75);
        self::assertSame(0.75, $progress->progress);
        self::assertSame(['7', 'Title', 'https://x/y', 'inline-art', "\e[1mStyled", 'sixel-bytes', 42],
            [$progress->id, $progress->title, $progress->posterUrl, $progress->poster, $progress->styledTitle, $progress->posterImage, $progress->imageId]);

        $styled = $card->withStyledTitle("\e[33mHot");
        self::assertSame("\e[33mHot", $styled->styledTitle);
        self::assertSame(['7', 'Title', 'https://x/y', 0.5, 'inline-art', 'sixel-bytes', 42],
            [$styled->id, $styled->title, $styled->posterUrl, $styled->progress, $styled->poster, $styled->posterImage, $styled->imageId]);

        $safe = $card->withSafePoster('safe-art');
        self::assertSame('safe-art', $safe->poster);
        self::assertSame(['7', 'Title', 'https://x/y', 0.5, "\e[1mStyled", 'sixel-bytes', 42],
            [$safe->id, $safe->title, $safe->posterUrl, $safe->progress, $safe->styledTitle, $safe->posterImage, $safe->imageId]);
    }

    public function testTheConstructorIsHandSpelledExactlyTwiceInTheSource(): void
    {
        // new() mirrors the public constructor (it receives every field as a
        // parameter — nothing can be dropped), and carrying() is the one
        // internal rebuild. A third CODE-site `new self(` is a re-introduced
        // hand-rolled carrier: consolidate it instead. Comment lines are
        // filtered so prose may freely name the pattern.
        $source = file_get_contents((new ReflectionClass(PosterCard::class))->getFileName());
        self::assertIsString($source);
        $code = implode("\n", array_filter(
            explode("\n", $source),
            static fn (string $line): bool => !preg_match('/^\s*(\*|\/\/)/', $line),
        ));
        self::assertSame(2, substr_count($code, 'new self('), 'PosterCard gained a hand-rolled carrier reconstruction');
    }

    #[DataProvider('clearingInputs')]
    public function testOverridesMayClearNullableFields(string $field): void
    {
        $card = $this->fullCard();
        $carrying = new ReflectionMethod(PosterCard::class, 'carrying');
        $result = $carrying->invoke($card, [$field => null]);
        self::assertNull($result->$field, "$field must accept an explicit null override");
    }

    /** @return array<string, array{0: string}> */
    public static function clearingInputs(): array
    {
        return [
            'posterUrl' => ['posterUrl'],
            'progress' => ['progress'],
            'poster' => ['poster'],
            'styledTitle' => ['styledTitle'],
            'posterImage' => ['posterImage'],
            'imageId' => ['imageId'],
        ];
    }
}

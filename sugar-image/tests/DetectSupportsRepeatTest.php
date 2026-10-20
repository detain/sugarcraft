<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;

/**
 * A-02: the REP capability gate. Truth rows of the env ladder plus the
 * test-seam polarity, until the S4.2 TermDb table replaces the body.
 */
final class DetectSupportsRepeatTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->saved['TERM'] = getenv('TERM');
        Detect::clearSupportsRepeatOverride();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
        Detect::clearSupportsRepeatOverride();
        parent::tearDown();
    }

    /**
     * @return array{string, bool}[]
     */
    public static function termTable(): array
    {
        return [
            'no TERM at all'        => [ '', false ],
            'dumb terminal'         => [ 'dumb', false ],
            'screen keeps its own buffer' => [ 'screen', false ],
            'screen-256color too'   => [ 'screen-256color', false ],
            'xterm-class'           => [ 'xterm-256color', true ],
            'vte'                   => [ 'xterm-vt220', true ],
            'kitty'                 => [ 'xterm-kitty', true ],
            'mlterm'                => [ 'mlterm', true ],
            'tmux default'          => [ 'tmux-256color', true ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('termTable')]
    public function testEnvTruthTable(string $term, bool $expected): void
    {
        putenv($term === '' ? 'TERM' : "TERM=$term");

        self::assertSame($expected, Detect::supportsRepeat(), "TERM=[{$term}]");
    }

    public function testOverrideShortCircuitsTheTable(): void
    {
        putenv('TERM=xterm-256color');
        Detect::setSupportsRepeatOverride(false);
        self::assertFalse(Detect::supportsRepeat(), 'a forced NO outranks every env row');

        putenv('TERM=dumb');
        Detect::setSupportsRepeatOverride(true);
        self::assertTrue(Detect::supportsRepeat(), 'a forced YES outranks every env row');

        Detect::setSupportsRepeatOverride(null);
        putenv('TERM=xterm-256color');
        self::assertTrue(Detect::supportsRepeat(), 'null clears back to the table');
    }
}

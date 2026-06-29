<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\Category;
use SugarCraft\Dash\Keys\Key;
use SugarCraft\Dash\Keys\KeyIdentifier;
use SugarCraft\Dash\Keys\KeyRegistry;

/**
 * Tests for the central key binding registry.
 */
final class KeyRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        KeyRegistry::reset();
    }

    protected function tearDown(): void
    {
        KeyRegistry::reset();
        parent::tearDown();
    }

    public function testRegisterAndGet(): void
    {
        $id = KeyIdentifier::quit();
        $key = new Key('q', ctrl: true);
        KeyRegistry::register($id, $key, 'Quit application', 'navigation');

        $meta = KeyRegistry::get($id);
        $this->assertNotNull($meta);
        $this->assertSame($key->key, $meta->key->key);
        $this->assertSame('Quit application', $meta->description);
        $this->assertSame('navigation', $meta->category);
    }

    public function testGetByStringIdentifier(): void
    {
        $id = KeyIdentifier::quit();
        $key = new Key('q');
        KeyRegistry::register($id, $key, 'Quit', 'general');

        // get() is case-sensitive; KeyIdentifier::quit()->value is 'quit'
        $meta = KeyRegistry::get('quit');
        $this->assertNotNull($meta);
    }

    public function testGetNonExistentReturnsNull(): void
    {
        $this->assertNull(KeyRegistry::get('NonExistent'));
    }

    public function testByCategory(): void
    {
        KeyRegistry::register(KeyIdentifier::quit(), new Key('q'), 'Quit', 'navigation');
        KeyRegistry::register(KeyIdentifier::help(), new Key('h'), 'Help', 'navigation');
        KeyRegistry::register(KeyIdentifier::refresh(), new Key('r'), 'Refresh', 'general');

        $nav = KeyRegistry::byCategory('navigation');
        $this->assertCount(2, $nav);

        $general = KeyRegistry::byCategory('general');
        $this->assertCount(1, $general);
    }

    public function testCategories(): void
    {
        KeyRegistry::register(KeyIdentifier::quit(), new Key('q'), 'Quit', 'nav');
        KeyRegistry::register(KeyIdentifier::help(), new Key('h'), 'Help', 'help');

        $cats = KeyRegistry::categories();
        $this->assertContains('nav', $cats);
        $this->assertContains('help', $cats);
    }

    public function testMatch(): void
    {
        KeyRegistry::register(KeyIdentifier::quit(), new Key('q', ctrl: true), 'Quit', 'general');

        $matched = KeyRegistry::match(new Key('q', ctrl: true));
        $this->assertNotNull($matched);
        $this->assertSame(KeyIdentifier::quit()->value, $matched->id->value);

        $noMatch = KeyRegistry::match(new Key('q', ctrl: false));
        $this->assertNull($noMatch);
    }

    public function testResetClearsAllBindings(): void
    {
        KeyRegistry::register(KeyIdentifier::quit(), new Key('q'), 'Quit', 'general');
        $this->assertNotNull(KeyRegistry::get(KeyIdentifier::quit()));

        KeyRegistry::reset();

        $this->assertNull(KeyRegistry::get(KeyIdentifier::quit()));
        $this->assertSame([], KeyRegistry::categories());
    }

    public function testRegisterSameIdOverwrites(): void
    {
        KeyRegistry::register(KeyIdentifier::quit(), new Key('q'), 'Old', 'gen');
        KeyRegistry::register(KeyIdentifier::quit(), new Key('x'), 'New', 'gen');

        $meta = KeyRegistry::get(KeyIdentifier::quit());
        $this->assertSame('New', $meta->description);
    }
}

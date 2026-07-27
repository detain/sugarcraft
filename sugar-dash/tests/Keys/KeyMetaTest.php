<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Keys;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Keys\KeyMeta;
use SugarCraft\Dash\Keys\KeyIdentifier;
use SugarCraft\Dash\Keys\Key;

final class KeyMetaTest extends TestCase
{
    public function testConstruction(): void
    {
        $id = KeyIdentifier::quit();
        $key = new Key('q', ctrl: true);
        $meta = new KeyMeta($id, $key, 'Quit the application', 'general');

        $this->assertSame($id, $meta->id);
        $this->assertSame($key, $meta->key);
        $this->assertSame('Quit the application', $meta->description);
        $this->assertSame('general', $meta->category);
    }

    public function testConstructionWithDefaultCategory(): void
    {
        $id = KeyIdentifier::help();
        $key = new Key('h');
        $meta = new KeyMeta($id, $key, 'Show help');

        $this->assertSame('general', $meta->category);
    }

    public function testDisplay(): void
    {
        $id = KeyIdentifier::quit();
        $key = new Key('Q', ctrl: true);
        $meta = new KeyMeta($id, $key, 'Quit');

        $this->assertSame('Ctrl+Q', $meta->display());
    }

    public function testDisplaySimpleKey(): void
    {
        $id = KeyIdentifier::help();
        $key = new Key('h');
        $meta = new KeyMeta($id, $key, 'Help');

        $this->assertSame('H', $meta->display());
    }

    public function testFullDescription(): void
    {
        $id = KeyIdentifier::refresh();
        $key = new Key('r', ctrl: true);
        $meta = new KeyMeta($id, $key, 'Refresh the display', 'navigation');

        $this->assertSame('[navigation] Refresh the display', $meta->fullDescription());
    }

    public function testFullDescriptionDefaultCategory(): void
    {
        $id = KeyIdentifier::quit();
        $key = new Key('q');
        $meta = new KeyMeta($id, $key, 'Quit the app');

        $this->assertSame('[general] Quit the app', $meta->fullDescription());
    }
}

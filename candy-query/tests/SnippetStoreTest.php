<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use JsonException;
use SugarCraft\Query\Snippet;
use SugarCraft\Query\SnippetStore;
use PHPUnit\Framework\TestCase;

final class SnippetStoreTest extends TestCase
{
    private string $tmpPath;

    protected function setUp(): void
    {
        $this->tmpPath = sys_get_temp_dir() . '/snippet-store-test-' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpPath)) {
            unlink($this->tmpPath);
        }
    }

    public function testEmptyStoreHasNoSnippets(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $this->assertSame([], $store->snippets);
        $this->assertSame($this->tmpPath, $store->path);
    }

    public function testSaveAddsSnippet(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('my-query', 'SELECT * FROM users');
        $this->assertCount(1, $store->snippets);
        $this->assertSame('my-query', $store->snippets[0]->name);
        $this->assertSame('SELECT * FROM users', $store->snippets[0]->sql);
    }

    public function testSaveEmptyNameIsNoOp(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('', 'SELECT 1');
        $this->assertCount(0, $store->snippets);
    }

    public function testSaveEmptySqlIsNoOp(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('name', '');
        $this->assertCount(0, $store->snippets);
    }

    public function testSaveReplacesDuplicateName(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('q', 'SELECT 1');
        $store = $store->add('q', 'SELECT 2');
        $this->assertCount(1, $store->snippets);
        $this->assertSame('SELECT 2', $store->snippets[0]->sql);
    }

    public function testDeleteRemovesSnippet(): void
    {
        $store = (new SnippetStore([], $this->tmpPath))
            ->add('a', 'SELECT 1')
            ->add('b', 'SELECT 2');
        $store = $store->delete('a');
        $this->assertCount(1, $store->snippets);
        $this->assertSame('b', $store->snippets[0]->name);
    }

    public function testDeleteMissingNameIsNoOp(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('a', 'SELECT 1');
        $store = $store->delete('nonexistent');
        $this->assertCount(1, $store->snippets);
    }

    public function testFindReturnsSnippet(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('find-me', 'SELECT id FROM users');
        $found = $store->find('find-me');
        $this->assertInstanceOf(Snippet::class, $found);
        $this->assertSame('SELECT id FROM users', $found->sql);
    }

    public function testFindReturnsNullWhenMissing(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $this->assertNull($store->find('nonexistent'));
    }

    public function testSearchFindsByName(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('users-all', 'SELECT * FROM users');
        $store = $store->add('users-active', 'SELECT * FROM users WHERE active = 1');
        $store = $store->add('posts-all', 'SELECT * FROM posts');

        $results = $store->search('users');
        $this->assertCount(2, $results);
    }

    public function testSearchFindsBySql(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('q1', 'SELECT id FROM users');
        $store = $store->add('q2', 'SELECT id FROM posts');

        $results = $store->search('users');
        $this->assertCount(1, $results);
        $this->assertSame('q1', $results[0]->name);
    }

    public function testSearchEmptyTermReturnsAll(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('a', 'SELECT 1');
        $store = $store->add('b', 'SELECT 2');
        $this->assertCount(2, $store->search(''));
    }

    public function testLoadFromFile(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('from-file', 'SELECT 42');
        $store->flush();

        $loaded = SnippetStore::load($this->tmpPath);
        $this->assertCount(1, $loaded->snippets);
        $this->assertSame('from-file', $loaded->snippets[0]->name);
    }

    public function testLoadMissingFileReturnsEmpty(): void
    {
        $store = SnippetStore::load('/nonexistent/path/to/file.json');
        $this->assertSame([], $store->snippets);
    }

    public function testLoadCorruptJsonReturnsEmpty(): void
    {
        file_put_contents($this->tmpPath, '{invalid json}');
        $store = SnippetStore::load($this->tmpPath);
        $this->assertSame([], $store->snippets);
    }

    public function testSavePersistsDescription(): void
    {
        $store = new SnippetStore([], $this->tmpPath);
        $store = $store->add('with-desc', 'SELECT 1', 'A useful query');
        $store->flush();

        $loaded = SnippetStore::load($this->tmpPath);
        $this->assertSame('A useful query', $loaded->snippets[0]->description);
    }
}

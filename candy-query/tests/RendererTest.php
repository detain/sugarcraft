<?php

declare(strict_types=1);

namespace CandyCore\Query\Tests;

use CandyCore\Core\KeyType;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Query\App;
use CandyCore\Query\Database;
use CandyCore\Query\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function db(): Database
    {
        $db = new Database(new \PDO('sqlite::memory:'));
        $db->pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $db->pdo->exec("INSERT INTO users VALUES (1, 'alice'), (2, 'bob')");
        return $db;
    }

    public function testRenderIncludesTitleHeader(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('CandyQuery', $out);
    }

    public function testRenderShowsTablesAndRows(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('users', $out);
        $this->assertStringContainsString('alice', $out);
        $this->assertStringContainsString('bob', $out);
    }

    public function testRenderShowsHelpFooter(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('switch pane', $out);
        $this->assertStringContainsString('run query', $out);
    }

    public function testRenderShowsEmptyState(): void
    {
        $emptyDb = new Database(new \PDO('sqlite::memory:'));
        $a = App::start($emptyDb);
        $out = Renderer::render($a);
        $this->assertStringContainsString('no tables', $out);
        $this->assertStringContainsString('empty', $out);
    }

    public function testRenderShowsQueryPromptHint(): void
    {
        $out = Renderer::render(App::start($this->db()));
        $this->assertStringContainsString('type SQL', $out);
    }

    public function testRenderShowsErrorBanner(): void
    {
        $a = App::start($this->db());
        // Switch to query pane and submit an invalid SQL.
        [$a] = $a->update(new KeyMsg(KeyType::Tab, ''));
        [$a] = $a->update(new KeyMsg(KeyType::Tab, ''));
        foreach (str_split('NOPE') as $c) {
            [$a] = $a->update(new KeyMsg(KeyType::Char, $c));
        }
        [$a] = $a->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $out = Renderer::render($a);
        $this->assertStringContainsString('error', $out);
    }
}

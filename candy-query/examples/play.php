<?php

declare(strict_types=1);

/**
 * Spin up an in-memory SQLite, seed it, drop into the candy-query
 * dashboard.
 *
 *   php examples/play.php
 */
require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;
use CandyCore\Query\App;
use CandyCore\Query\Database;

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT)');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, author_id INTEGER)');
$pdo->exec("INSERT INTO users (name, role) VALUES
    ('alice', 'admin'),
    ('bob',   'editor'),
    ('carol', 'viewer'),
    ('dave',  'editor')");
$pdo->exec("INSERT INTO posts (title, author_id) VALUES
    ('hello world', 1),
    ('first post',  2),
    ('intro',       3)");

(new Program(App::start(new Database($pdo)), new ProgramOptions(useAltScreen: true)))->run();

<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\ReadOnlyBashClassifier;

final class ReadOnlyBashClassifierTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function readOnlyCommands(): iterable
    {
        yield 'git status' => ['git status'];
        yield 'git status short' => ['git status --short'];
        yield 'git log bounded' => ['git log --oneline -5'];
        yield 'git diff against a ref' => ['git diff HEAD~1'];
        yield 'git show a path' => ['git show HEAD:README.md'];
        yield 'ls with flags' => ['ls -la src'];
        yield 'cat a file' => ['cat README.md'];
        yield 'head with count' => ['head -n 20 src/Runtime.php'];
        yield 'tail with count' => ['tail -n 5 composer.json'];
        yield 'wc on a quoted path' => ['wc -l "src/a file.php"'];
        yield 'grep recursive' => ['grep -rn "function execute" src'];
        yield 'rg search' => ['rg -n TODO src'];
        yield 'find by name' => ['find . -name README.md'];
        yield 'pwd' => ['pwd'];
        yield 'echo a word' => ['echo hello'];
        yield 'which a binary' => ['which php'];
        yield 'surrounding whitespace' => ["  ls  "];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedCommands(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'write by redirect' => ['cat a > b'];
        yield 'append by redirect' => ['echo x >> notes'];
        yield 'read redirect' => ['wc -l < file'];
        yield 'sequence' => ['ls; rm x'];
        yield 'and-chain' => ['ls && rm -rf x'];
        yield 'or-chain' => ['ls || rm x'];
        yield 'pipe' => ['ls | sh'];
        yield 'background' => ['sleep 5 &'];
        yield 'command substitution' => ['echo $(id)'];
        yield 'variable' => ['cat $HOME/.bashrc'];
        yield 'backticks' => ['echo `id`'];
        yield 'subshell' => ['(ls)'];
        yield 'brace group' => ['{ ls; }'];
        yield 'newline joins a second command' => ["ls\nrm x"];
        yield 'carriage return' => ["ls\rrm x"];
        yield 'backslash escape' => ['cat a\\b'];
        yield 'glob that could name an option file' => ['git diff *'];
        yield 'question-mark glob' => ['cat file?.txt'];
        yield 'bracket glob' => ['cat file[1].txt'];
        yield 'history expansion' => ['ls !!'];
        yield 'comment' => ['ls # rm x'];
        yield 'unbalanced quote' => ['ls "unterminated'];
        yield 'env assignment prefix' => ['FOO=bar ls'];
        yield 'program not on the list' => ['rm -rf build'];
        yield 'sed is not read only' => ['sed -n 1p file'];
        yield 'awk runs programs' => ['awk "BEGIN{system(\"id\")}"'];
        yield 'xargs' => ['xargs rm'];
        yield 'find -exec' => ['find . -name x -exec rm {} +'];
        yield 'find -execdir' => ['find . -execdir rm {} +'];
        yield 'find -delete' => ['find . -name x -delete'];
        yield 'find -fprint writes a file' => ['find . -fprint out.txt'];
        yield 'find -fprint0 writes a file' => ['find . -fprint0 out.bin'];
        yield 'rg --pre runs a program' => ['rg --pre=sh foo'];
        yield 'tail -f never returns' => ['tail -f log'];
        yield 'tail -F never returns' => ['tail -F log'];
        yield 'tail --follow never returns' => ['tail --follow=name log'];
        yield 'git with a leading config override' => ['git -c core.pager=sh log'];
        yield 'git with exec-path' => ['git --exec-path=/tmp status'];
        yield 'git diff --output writes a file' => ['git diff --output=out.patch'];
        yield 'git log --output writes a file' => ['git log --output=out.txt'];
        yield 'git show textconv runs a program' => ['git show --textconv HEAD:file'];
        yield 'git commit writes' => ['git commit -m x'];
        yield 'git push writes' => ['git push'];
        yield 'git with no subcommand' => ['git'];
    }

    #[DataProvider('readOnlyCommands')]
    public function testAPlainReadIsReadOnly(string $command): void
    {
        self::assertTrue(ReadOnlyBashClassifier::isReadOnly($command), $command);
    }

    #[DataProvider('rejectedCommands')]
    public function testAnythingElseIsRefused(string $command): void
    {
        self::assertFalse(ReadOnlyBashClassifier::isReadOnly($command), $command);
    }
}

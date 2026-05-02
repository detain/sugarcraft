# SugarSpark

PHP port of [charmbracelet/sequin](https://github.com/charmbracelet/sequin) —
an ANSI escape-sequence inspector. Pipe styled output through it and
each escape becomes a labelled line:

```sh
$ printf '\e[31mhello\e[0m world\n' | sugarspark
ESC[31m  SGR foreground red
hello
ESC[0m   SGR reset
 world
```

Works as a library too:

```php
use CandyCore\Spark\Inspector;

foreach (Inspector::parse("\e[1;31mboom\e[0m") as $segment) {
    echo $segment->describe(), "\n";
}
```

## Test

```sh
cd sugar-spark && composer install && vendor/bin/phpunit
```

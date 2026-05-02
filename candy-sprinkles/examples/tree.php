<?php

declare(strict_types=1);

/**
 * Tree — render a project structure under three different
 * enumerator styles.
 *
 *   php examples/tree.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Sprinkles\Tree\Enumerator;
use CandyCore\Sprinkles\Tree\Tree;

$buildTree = static fn() => Tree::new()
    ->root('sugarcraft/')
    ->children(
        Tree::new()->root('candy-core/')->children('src/', 'tests/', 'examples/'),
        Tree::new()->root('candy-sprinkles/')->children('src/', 'tests/', 'examples/'),
        Tree::new()->root('sugar-bits/')->children('src/', 'tests/', 'examples/'),
        'README.md',
        'composer.json',
    );

foreach (['default', 'rounded', 'ascii'] as $name) {
    $enum = match ($name) {
        'rounded' => Enumerator::rounded(),
        'ascii'   => Enumerator::ascii(),
        default   => Enumerator::default(),
    };
    echo "\x1b[36m$name\x1b[0m\n" . $buildTree()->enumerator($enum)->render() . "\n\n";
}

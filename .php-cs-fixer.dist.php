<?php

$finder = PhpCsFixer\Finder::create()
    ->exclude('vendor')
    ->exclude('.phpunit.cache')
    ->in(__DIR__);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        // candy-git CG-55 (finding candy-git-080, 2026-11-16): PHP constants
        // are spelled lower-case (true/false/null) fleet-wide; pin the style
        // here so a future fixer run cannot drift it back.
        'constant_case' => ['case' => 'lower'],
    ])
    ->setFinder($finder);

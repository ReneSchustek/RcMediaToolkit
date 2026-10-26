<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->exclude('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_closing_tag' => true,
        'modernize_types_casting' => true,
        'no_alias_functions' => true,
        'phpdoc_align' => false,
        'no_superfluous_phpdoc_tags' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'parameters']],
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false, 'import_constants' => false],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__ . '/var/.php-cs-fixer.cache');

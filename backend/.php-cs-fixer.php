<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP84Migration' => false,
        'array_syntax' => ['syntax' => 'short'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'parameters']],
        'blank_line_before_statement' => ['statements' => ['return']],
        'php_unit_test_class_requires_covers' => false,
    ])
    ->setFinder($finder);

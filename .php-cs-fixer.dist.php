<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->notPath('vendor');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                                  => true,
        '@PHP82Migration'                         => true,
        'strict_param'                            => true,
        'declare_strict_types'                    => true,
        'array_syntax'                            => ['syntax' => 'short'],
        'ordered_imports'                         => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'                       => true,
        'not_operator_with_successor_space'       => true,
        'trailing_comma_in_multiline'             => ['elements' => ['arrays', 'arguments', 'parameters']],
        'phpdoc_order'                            => true,
        'phpdoc_separation'                       => true,
        'phpdoc_single_line_var_spacing'          => true,
        'phpdoc_var_without_name'                 => true,
        'class_attributes_separation'             => ['elements' => ['method' => 'one', 'property' => 'one']],
        'method_argument_space'                   => ['on_multiline' => 'ensure_fully_multiline'],
        'single_quote'                            => true,
        'no_extra_blank_lines'                    => ['tokens' => ['extra', 'throw', 'use']],
        'no_whitespace_before_comma_in_array'     => true,
        'whitespace_after_comma_in_array'         => true,
        'blank_line_before_statement'             => ['statements' => ['return', 'throw', 'try']],
        'no_superfluous_phpdoc_tags'              => ['allow_mixed' => true],
        'fully_qualified_strict_types'            => true,
        'global_namespace_import'                 => ['import_classes' => false, 'import_constants' => false],
        'concat_space'                            => ['spacing' => 'one'],
        'binary_operator_spaces'                  => ['default' => 'align_single_space_minimal'],
        'object_operator_without_whitespace'      => true,
        'semicolon_after_instruction'             => true,
        'no_empty_comment'                        => true,
        'no_empty_phpdoc'                         => true,
        'no_empty_statement'                      => true,
        'no_leading_namespace_whitespace'         => true,
        'cast_spaces'                             => ['space' => 'single'],
        'class_definition'                        => ['multi_line_extends_each_single_line' => true],
        'yoda_style'                              => false,
        'native_function_casing'                  => true,
        'native_function_type_declaration_casing' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);

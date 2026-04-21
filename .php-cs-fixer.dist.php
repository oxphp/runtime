<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0'              => true,
        '@PER-CS2.0:risky'        => true,
        '@PHP84Migration'          => true,
        'declare_strict_types'    => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'ordered_imports'          => true,
        'no_unused_imports'        => true,
    ])
    ->setFinder($finder);

<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised when an operation would take stock below zero and negative stock is not allowed. */
class InsufficientStockException extends RuntimeException
{
    public function __construct(
        public readonly string $productName,
        public readonly string $available,
        public readonly string $requested,
        string $unit = '',
    ) {
        $unit = $unit !== '' ? " {$unit}" : '';

        parent::__construct("Not enough stock for \"{$productName}\": {$available}{$unit} available, {$requested}{$unit} needed.");
    }
}

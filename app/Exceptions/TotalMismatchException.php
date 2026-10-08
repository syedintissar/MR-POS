<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * The total the cashier saw on the screen is not what the server worked out (a price or tax setting changed while the sale
 * was open). Nothing was charged or saved; the screen shows the server's figure and the cashier decides.
 */
class TotalMismatchException extends DomainException
{
    public function __construct(public readonly string $serverTotal, public readonly string $clientTotal)
    {
        parent::__construct("The total changed from {$clientTotal} to {$serverTotal} (a price or setting was updated). Please check the sale and try again.");
    }
}

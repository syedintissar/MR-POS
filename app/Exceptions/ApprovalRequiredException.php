<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A sale needs a manager's (or administrator's) approval before it can be completed: a discount above the cashier's limit,
 * a changed price, a price below the minimum, or the wholesale price list. Nothing has been saved when this is thrown.
 */
class ApprovalRequiredException extends DomainException
{
    /** @param  list<array{code: string, message: string, permission: string}>  $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('Manager approval is needed: '.implode('; ', array_column($reasons, 'message')).'.');
    }

    /** @return list<string> the permissions an approver must hold to approve all of it */
    public function permissions(): array
    {
        return array_values(array_unique(array_column($this->reasons, 'permission')));
    }
}

<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Another customer already has this phone number (SRS CUS-10). A warning, not a ban: families and companies share numbers. */
class DuplicatePhoneException extends DomainException
{
    /** @param  list<string>  $names the customers that already use the number */
    public function __construct(public readonly array $names)
    {
        parent::__construct('Another customer already has this phone number ('.implode(', ', $names).'). Tick "save anyway" if it really is a different customer.');
    }
}

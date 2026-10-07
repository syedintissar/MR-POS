<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised for any backup, verification or restore failure. The message is safe to show to an administrator. */
class BackupException extends RuntimeException {}

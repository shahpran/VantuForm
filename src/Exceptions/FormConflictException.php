<?php

declare(strict_types=1);

namespace VanturaxForms\Exceptions;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thrown when an optimistic concurrency check fails because another user
 * updated the target form revision before the current save completed.
 */
final class FormConflictException extends \RuntimeException
{
}

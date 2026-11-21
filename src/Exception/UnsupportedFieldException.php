<?php

declare(strict_types=1);

namespace FormBridge\Exception;

/**
 * A form has a field that cannot be described as tool input. Thrown instead of silently leaving
 * the field out.
 */
final class UnsupportedFieldException extends \LogicException
{
}

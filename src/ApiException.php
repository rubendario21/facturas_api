<?php

declare(strict_types=1);

namespace App;

/** Error que se devuelve al cliente con su código HTTP y un mensaje legible. */
final class ApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

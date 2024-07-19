<?php

namespace antogkou\ApexClient\Exceptions;

use Exception;
use Throwable;

class SalesforceApiException extends Exception
{
    private array $context;

    public function __construct(string $message, int $code, ?Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getResponse(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'context' => $this->getContext(),
        ];
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

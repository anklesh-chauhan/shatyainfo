<?php

namespace App\Services\DataImport;

use JsonSerializable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportDownloadResponse extends StreamedResponse implements JsonSerializable
{
    public static function from(StreamedResponse $response): self
    {
        return new self($response->getCallback(), $response->getStatusCode(), $response->headers->all());
    }

    /** Livewire delivers the file through its download effect, not the JSON action return value. */
    public function jsonSerialize(): mixed
    {
        return null;
    }
}

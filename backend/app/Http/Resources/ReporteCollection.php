<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class ReporteCollection extends ResourceCollection
{
    public function __construct(mixed $resource, string $collects)
    {
        $this->collects = $collects;
        parent::__construct($resource);
    }

    public function jsonOptions(): int
    {
        return JSON_PRESERVE_ZERO_FRACTION;
    }
}

<?php

declare(strict_types=1);

namespace SFC\Staticfilecache\Event;

class PoolEvent
{
    protected array $information;

    public function __construct(array $information)
    {
        $this->information = $information;
    }

    public function getInformation(): array
    {
        return $this->information;
    }
}

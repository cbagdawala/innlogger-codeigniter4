<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

final class NullContextProvider implements ContextProviderInterface
{
    public function context(): array
    {
        return [];
    }
}

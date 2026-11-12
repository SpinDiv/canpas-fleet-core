<?php

declare(strict_types=1);

namespace Canpas\Fleet\Support;

final class DbError extends \RuntimeException
{
    public function __construct(string $message, public readonly string $sql = '')
    {
        parent::__construct($message);
    }

    public function isDuplicateKey(string $key = ''): bool
    {
        $m = $this->getMessage();
        if (stripos($m, 'Duplicate entry') === false) {
            return false;
        }
        return $key === '' || str_contains($m, "'" . $key . "'") || str_contains($m, '.' . $key . "'");
    }
}

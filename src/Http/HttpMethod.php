<?php

declare(strict_types=1);

namespace Payxy\Http;

enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';

    public function hasBody(): bool
    {
        return $this === self::Post;
    }
}

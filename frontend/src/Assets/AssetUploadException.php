<?php
declare(strict_types=1);

namespace App\Assets;

use RuntimeException;

final class AssetUploadException extends RuntimeException
{
    /** @var array<string,mixed> */
    public array $extra;

    public int $httpStatus;

    /** @param array<string,mixed> $extra */
    public function __construct(string $code, int $httpStatus = 400, array $extra = [])
    {
        parent::__construct($code);
        $this->httpStatus = $httpStatus;
        $this->extra      = $extra;
    }
}

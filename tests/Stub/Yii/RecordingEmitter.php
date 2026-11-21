<?php

declare(strict_types=1);

namespace OxPHP\Runtime\Tests\Stub\Yii;

use Psr\Http\Message\ResponseInterface;
use Yiisoft\PsrEmitter\EmitterInterface;

/**
 * Keeps the responses it is given instead of sending them.
 */
final class RecordingEmitter implements EmitterInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

    public function emit(ResponseInterface $response): void
    {
        $this->responses[] = $response;
    }
}

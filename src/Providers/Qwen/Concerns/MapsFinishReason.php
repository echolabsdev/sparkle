<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Qwen\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Qwen\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'output.choices.0.finish_reason', ''));
    }
}

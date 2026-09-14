<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Azure\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Azure\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        return FinishReasonMap::map(data_get($data, 'choices.0.finish_reason', ''));
    }
}

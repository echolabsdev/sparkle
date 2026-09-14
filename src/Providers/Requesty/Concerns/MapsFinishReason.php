<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Requesty\Concerns;

use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Requesty\Maps\FinishReasonMap;

trait MapsFinishReason
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function mapFinishReason(array $data): FinishReason
    {
        $finishReason = data_get($data, 'choices.0.finish_reason', '');

        return FinishReasonMap::map($finishReason ?? '');
    }
}

<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

enum BatchResultStatus: string
{
    case Succeeded = 'succeeded';
    case Errored = 'errored';
    case Canceled = 'canceled';
    case Expired = 'expired';
}

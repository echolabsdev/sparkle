<?php

declare(strict_types=1);

namespace Prism\Prism\Batch;

enum BatchStatus: string
{
    case Validating = 'validating';
    case InProgress = 'in_progress';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}

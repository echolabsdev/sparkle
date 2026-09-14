<?php

declare(strict_types=1);

namespace Prism\Prism\Enums;

enum TelemetryOperation: string
{
    case Text = 'text';
    case Structured = 'structured';
    case Embeddings = 'embeddings';
    case Image = 'image';
    case Stream = 'stream';
}

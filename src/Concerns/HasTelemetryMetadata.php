<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HasTelemetryMetadata
{
    protected ?string $telemetryUserId = null;

    protected ?string $telemetrySessionId = null;

    public function withTelemetryMetadata(?string $userId = null, ?string $sessionId = null): self
    {
        $this->telemetryUserId = $userId;
        $this->telemetrySessionId = $sessionId;

        return $this;
    }
}

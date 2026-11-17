<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case PENDING = 'pending';

    case QUEUED = 'queued';

    case PROCESSING = 'processing';

    case SUCCEEDED = 'succeeded';

    case FAILED = 'failed';

    /**
     * Forward moves only, and skipping is OK because webhooks can be lost or delivered out-of-order.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::QUEUED, self::PROCESSING, self::SUCCEEDED, self::FAILED],
            self::QUEUED => [self::PROCESSING, self::SUCCEEDED, self::FAILED],
            self::PROCESSING => [self::SUCCEEDED, self::FAILED],
            self::SUCCEEDED, self::FAILED => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}

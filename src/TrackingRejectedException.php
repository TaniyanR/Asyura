<?php
declare(strict_types=1);

namespace Asyura;

final class TrackingRejectedException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 403, private string $eventCode = 'rejected')
    {
        parent::__construct($message);
    }

    public function status(): int { return $this->status; }
    public function eventCode(): string { return $this->eventCode; }
}

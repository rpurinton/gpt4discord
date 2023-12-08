<?php

namespace RPurinton\Framework2\Consumers;

use React\EventLoop\{LoopInterface, TimerInterface};
use RPurinton\Framework2\{Log, Error};

class Timers
{
    public function __construct(private Log $log, private LoopInterface $loop)
    {
        $this->log->debug("Timers construct");
    }

    public function init(): bool
    {
        $this->log->debug("Timers init");
        $this->timer_300();
        $this->timer_15();
        $result15 = $this->loop->addPeriodicTimer(15, [$this, 'timer_15']) or throw new Error("failed to add periodic timer");
        $result300 = $this->loop->addPeriodicTimer(300, [$this, 'timer_300']) or throw new Error("failed to add periodic timer");
        $success = $result15 instanceof TimerInterface && $result300 instanceof TimerInterface;
        if (!$success) throw new Error("failed to add periodic timers");
        $this->log->info("periodic timers added");
        return $success;
    }

    public function timer_300(): void
    {
        $this->log->debug("timer_300 fired");
    }

    public function timer_15(): void
    {
        $this->log->debug("timer_15 fired");
    }
}

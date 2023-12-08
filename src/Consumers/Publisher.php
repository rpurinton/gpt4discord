<?php

namespace RPurinton\Framework2\Consumers;

use RPurinton\Framework2\{Log, Error};
use RPurinton\Framework2\RabbitMQ\Publisher as MQPublisher;

class Publisher
{

    private ?MQPublisher $pub = null;

    public function __construct(private Log $log)
    {
        $this->log->debug("Publisher construct");
    }

    public function init(): bool
    {
        $this->log->debug("Publisher init");
        if (!$this->pub) $this->pub = new MQPublisher() or throw new Error("failed to create Publisher");
        return true;
    }
}

<?php

namespace RPurinton\GPT4discord\Consumers;

use Bunny\{Channel, Message};
use React\EventLoop\LoopInterface;
use RPurinton\GPT4discord\{Log, Error, MySQL};
use RPurinton\GPT4discord\RabbitMQ\{Consumer, Publisher};

class DiscordClient
{
    public function __construct(private Log $log, private LoopInterface $loop, private Consumer $mq, private MySQL $sql)
    {
        $this->log->debug("DiscordClient construct");
    }

    public function init(): bool
    {
        $this->log->debug("DiscordClient init");
        $this->mq->connect($this->loop, "outbox", $this->callback(...)) or throw new Error("failed to connect to queue");
        return true;
    }

    public function callback(Message $message, Channel $channel): bool
    {
        $this->log->debug("received callback", [$message->content]);
        $channel->ack($message);
        return true;
    }
}

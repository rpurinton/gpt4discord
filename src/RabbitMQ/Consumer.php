<?php

namespace RPurinton\Framework2\RabbitMQ;

use RPurinton\Framework2\{Config, Error};
use React\{Async, EventLoop\LoopInterface};
use Bunny\{Async\Client, Channel};

class Consumer
{
    private ?Client $client = null;
    private ?Channel $channel = null;
    private ?string $consumerTag = null;
    private ?string $queue = null;

    public function connect(LoopInterface $loop, string $queue, callable $process): mixed
    {
        $this->queue = $queue;
        $this->consumerTag = bin2hex(random_bytes(8));
        $this->client = new Client($loop, Config::get("rabbitmq")) or throw new Error('Failed to establish the client');
        $this->client = Async\await($this->client->connect()) or throw new Error('Failed to establish the connection');
        $this->channel = Async\await($this->client->channel()) or throw new Error('Failed to establish the channel');
        $this->channel->qos(0, 1) or throw new Error('Failed to set the QoS');
        return Async\await($this->channel->consume($process, $this->queue, $this->consumerTag)) or throw new Error('Failed to consume the queue');
    }

    public function disconnect(): bool
    {
        if (isset($this->channel)) {
            $this->channel->cancel($this->consumerTag);
            $this->channel->queueDelete($this->queue);
            $this->channel->close();
        }
        if (isset($this->client)) {
            $this->client->disconnect();
        }
        return true;
    }

    public function __destruct()
    {
        $this->disconnect() or throw new Error('Failed to disconnect from RabbitMQ');
    }
}

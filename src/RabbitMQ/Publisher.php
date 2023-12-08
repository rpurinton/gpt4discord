<?php

namespace RPurinton\Framework2\RabbitMQ;

use RPurinton\Framework2\{Config, Error};
use Bunny\{Client, Channel};

class Publisher
{
    private ?Client $client = null;
    private ?Channel $channel = null;

    public function __construct()
    {
        $this->client = new Client(Config::get("rabbitmq")) or throw new Error('Failed to establish the client');
        $this->client = $this->client->connect() or throw new Error('Failed to establish the connection');
        $this->channel = $this->client->channel() or throw new Error('Failed to establish the channel');
    }

    public function publish(string $queue, array $data): bool
    {
        $result = false;
        try {
            $result = $this->channel->publish(json_encode($data), [], '', $queue) or throw new Error('Failed to publish the message');
        } catch (\Throwable $e) {
            print_r($e);
        } catch (\Error $e) {
            print_r($e);
        } catch (\Exception $e) {
            print_r($e);
        } catch (\Bunny\Exception\BunnyException $e) {
            print_r($e);
        }
        return $result;
    }

    public function disconnect(): bool
    {
        if (isset($this->channel) && $this->channel) {
            $this->channel->close();
        }
        if (isset($this->client) && $this->client) {
            $this->client->disconnect();
        }
        return true;
    }

    public function __destruct()
    {
        $this->disconnect() or throw new Error('Failed to disconnect from RabbitMQ');
    }
}

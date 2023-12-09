<?php

namespace RPurinton\GPT4discord\Consumers;

use Bunny\{Channel, Message};
use React\EventLoop\LoopInterface;
use RPurinton\GPT4discord\{Log, Error, MySQL};
use RPurinton\GPT4discord\RabbitMQ\{Consumer, Publisher};

class InboxHandler
{
    private ?Log $log = null;
    private ?LoopInterface $loop = null;
    private ?Consumer $mq = null;
    private ?Publisher $pub = null;
    private ?MySQL $sql = null;

    public function __construct(private array $config)
    {
        $this->validateConfig($config);
        $this->log = $config['log'];
        $this->loop = $config['loop'];
        $this->mq = $config['mq'];
        $this->pub = $config['pub'];
        $this->sql = $config['sql'];
        $this->log->debug("construct");
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => "Log",
            'loop' => "LoopInterface",
            'mq' => "Consumer",
            'pub' => "Publisher",
            'sql' => "MySQL"
        ];
        foreach ($requiredKeys as $key => $class) {
            if (!array_key_exists($key, $config)) throw new Error("missing required key $key");
            if (!is_a($config[$key], $class)) throw new Error("invalid type for $key");
        }
        return true;
    }

    public function init(): bool
    {
        $this->log->debug("init");
        $this->mq->connect($this->loop, "inbox", $this->callback(...)) or throw new Error("failed to connect to queue");
        return true;
    }

    public function callback(Message $message, Channel $channel): bool
    {
        $this->log->debug("callback", [$message->content]);
        $content = json_decode($message->content);
        if ($content->op === 11) // heartbeat
        {
            $this->sql->query("SELECT 1"); // keep MySQL connection alive
            $this->pub->publish("outbox", $content) or throw new Error("failed to publish message to outbox");
        }
        $channel->ack($message);
        return true;
    }
}

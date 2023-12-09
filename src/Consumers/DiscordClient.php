<?php

namespace RPurinton\GPT4discord\Consumers;

use Bunny\{Channel, Message};
use Discord\{Discord, WebSockets\Intents};
use Discord\Parts\User\Activity;
use React\{Async, EventLoop\LoopInterface};
use RPurinton\GPT4discord\{Log, Error, MySQL};
use RPurinton\GPT4discord\RabbitMQ\{Consumer, Publisher};
use stdClass;

class DiscordClient
{
    private ?string $token = null;
    private ?Discord $discord = null;

    public function __construct(private Log $log, private LoopInterface $loop, private Consumer $mq, private MySQL $sql)
    {
        $this->log->debug("construct");
    }

    public function init(): bool
    {
        $this->log->debug("init");
        $this->token = $this->getToken();
        $discord_config = [
            'token' => $this->token,
            'logger' => $this->log,
            'loop' => $this->loop,
            'intents' => Intents::getDefaultIntents()
        ];
        $this->discord = new Discord($discord_config);
        $this->discord->on("ready", $this->ready(...));
		$this->discord->run();
        return true;
	}

	private function ready()
	{
        $this->mq->connect($this->loop, "outbox", $this->callback(...)) or throw new Error("failed to connect to queue");
        $this->discord->on("raw", $this->raw(...));
        $activity = $this->discord->factory(Activity::class, [
			'name' => 'AI Language Model',
			'type' => Activity::TYPE_PLAYING
		]);
		$this->discord->updatePresence($activity);
        return true;
    }

    private function raw(stdClass $message, Discord $discord): bool // from Discord\Discord::onRaw
    {
        $this->log->debug("raw", ['message' => $message]);
        return true;
    }

    public function callback(Message $message, Channel $channel): bool // from RabbitMQ\Consumer::connect
    {
        $this->log->debug("callback", [$message->content]);
        $channel->ack($message);
        return true;
    }

    private function getToken(): string
    {
        $this->log->debug("getToken");
        $result = $this->sql->query("SELECT `discord_token` FROM `discord_tokens` LIMIT 1");
        if ($result === false) {
            throw new Error("failed to get discord_token");
        }
        if ($result->num_rows === 0) {
            throw new Error("no discord_token found");
        }
        $row = $result->fetch_assoc();
        $token = $row["discord_token"];
        $this->validateToken($token);
        return $token;
    }

    private function validateToken($token)
    {
        $this->log->debug("validateToken");
        if (!is_string($token)) {
            throw new Error("token is not a string");
        }
        if (strlen($token) === 0) {
            throw new Error("token is empty");
        }
        if (strlen($token) !== 72) {
            throw new Error("token is not 72 characters");
        }
        return true;
    }
}

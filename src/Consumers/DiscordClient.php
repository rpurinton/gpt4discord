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
        $this->log->debug('construct');
    }

    private function validateConfig(array $config): bool
    {
        $requiredKeys = [
            'log' => 'RPurinton\GPT4discord\Log',
            'loop' => 'React\EventLoop\LoopInterface',
            'mq' => 'RPurinton\GPT4discord\RabbitMQ\Consumer',
            'pub' => 'RPurinton\GPT4discord\RabbitMQ\Publisher',
            'sql' => 'RPurinton\GPT4discord\MySQL'
        ];
        foreach ($requiredKeys as $key => $class) {
            if (!array_key_exists($key, $config)) throw new Error('missing required key ' . $key);
            if (!is_a($config[$key], $class)) throw new Error('invalid type for ' . $key);
        }
        return true;
    }

    public function init(): bool
    {
        $this->log->debug('init');
        $this->token = $this->getToken();
        $discord_config = [
            'token' => $this->token,
            'logger' => $this->log,
            'loop' => $this->loop,
            'intents' => Intents::getDefaultIntents()
        ];
        $this->discord = new Discord($discord_config);
        $this->discord->on('ready', $this->ready(...));
        $this->discord->run();
        return true;
    }

    private function ready()
    {
        $this->mq->connect($this->loop, 'outbox', $this->callback(...)) or throw new Error('failed to connect to queue');
        $activity = $this->discord->factory(Activity::class, [
            'name' => 'AI Language Model',
            'type' => Activity::TYPE_PLAYING
        ]);
        $this->discord->updatePresence($activity);
        $this->discord->on('raw', $this->raw(...));
        return true;
    }

    private function raw(stdClass $message, Discord $discord): bool // from Discord\Discord::onRaw
    {
        $this->log->debug('raw', ['message' => $message]);
        if ($message->op === 11) $this->sql->query('SELECT 1'); // heartbeat / keep MySQL connection alive
        $this->pub->publish('inbox', $message) or throw new Error('failed to publish message to inbox');
        return true;
    }

    public function callback(Message $message, Channel $channel): bool // from RabbitMQ\Consumer::connect
    {
        $this->log->debug('callback', [$message->content]);
        $content = json_decode($message->content, true);
        if ($content['op'] === 11) $this->log->debug('heartbeat circuit complete');
        else $this->route($content) or throw new Error('failed to route message');
        $channel->ack($message);
        return true;
    }

    private function route(array $content): bool
    {
        $this->log->debug('route', [$content['t']]);
        switch ($content['t']) {
            case 'MESSAGE_CREATE':
                return $this->messageCreate($content['d']);
        }
        return true;
    }

    private function messageCreate(array $data): bool
    {
        $this->log->debug('messageCreate', ['data' => $data]);
        return true;
    }

    private function getToken(): string
    {
        $this->log->debug('getToken');
        $result = $this->sql->query('SELECT `discord_token` FROM `discord_tokens` LIMIT 1');
        if ($result === false) throw new Error('failed to get discord_token');
        if ($result->num_rows === 0) throw new Error('no discord_token found');
        $row = $result->fetch_assoc();
        $token = $row['discord_token'];
        $this->validateToken($token);
        return $token;
    }

    private function validateToken($token)
    {
        $this->log->debug('validateToken');
        if (!is_string($token)) throw new Error('token is not a string');
        if (strlen($token) === 0) throw new Error('token is empty');
        if (strlen($token) !== 72) throw new Error('token is not 72 characters');
        return true;
    }
}

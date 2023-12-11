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
        $this->mq->connect($this->loop, 'discord', $this->callback(...)) or throw new Error('failed to connect to queue');
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
        $queue = 'openai';
        if ($message->op === 11) {
            $this->sql->query('SELECT 1'); // heartbeat / keep MySQL connection alive
            $queue = 'fanout'; // send heartbeat to all consumers
        }
        $this->pub->publish($queue, $message) or throw new Error('failed to publish message to openai');
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

    private function splitMessage(string $content): array
    {
        $lines = explode('\n', $content . ' ');
        $mode = 'by_line';
        while (count($lines)) {
            $line = array_shift($lines);
            if (strlen($line) <= 2000) {
                return ['content' => $line, 'remaining' => $lines, 'mode' => $mode];
            }
            if ($mode !== 'by_char') {
                $split = $mode === 'by_line' ? explode('. ', $line) : explode(' ', $line);
                $mode = $mode === 'by_line' ? 'by_sentence' : 'by_word';
                $lines = array_merge($split, $lines);
            } else {
                $lines = array_merge(str_split($line), $lines);
            }
        }
        return ['content' => '', 'remaining' => [], 'mode' => $mode];
    }

    private function sendMessage(array $message): void
    {
        $this->discord->getChannel($message['channel_id'])->sendMessage($this->builder($message));
    }

    private function messageCreate(array $message): bool
    {
        $this->log->debug('messageCreate', ['message' => $message]);
        if (!isset($message['content']) || strlen($message['content']) < 2000) {
            $this->sendMessage($message);
            return true;
        }
        $content = $message['content'];
        while (strlen($content)) {
            $split = $this->splitMessage($content);
            $content = implode($split['mode'] === 'by_char' ? '' : ' ', $split['remaining']);
            $message['content'] = $split['content'];
            $this->sendMessage($message);
        }
        return true;
    }

    private function builder($message)
    {
        $builder = \Discord\Builders\MessageBuilder::new();
        $this->setContent($builder, $message);
        $this->addFileFromContent($builder, $message);
        $this->addAttachments($builder, $message);
        $this->addEmbeds($builder, $message);
        $this->setAllowedMentions($builder, $message);
        return $builder;
    }

    private function setContent($builder, $message)
    {
        if (isset($message['content'])) $builder->setContent($message['content']);
    }

    private function addFileFromContent($builder, $message)
    {
        if (isset($message['addFileFromContent'])) foreach ($message['addFileFromContent'] as $attachment) $builder->addFileFromContent($attachment['filename'], $attachment['content']);
    }

    private function addAttachments($builder, $message)
    {
        if (isset($message['attachments'])) foreach ($message['attachments'] as $attachment) {
            $embed = new \Discord\Parts\Embed\Embed($this->discord);
            $embed->setURL($attachment['url']);
            $embed->setImage($attachment['url']);
            $builder->addEmbed($embed);
        }
    }

    private function addEmbeds($builder, $message)
    {
        if (isset($message['embeds'])) foreach ($message['embeds'] as $old_embed) if ($old_embed['type'] == 'rich') {
            $new_embed = new \Discord\Parts\Embed\Embed($this->discord);
            $new_embed->fill($old_embed);
            $builder->addEmbed($new_embed);
        }
    }

    private function setAllowedMentions($builder, $message)
    {
        if (isset($message['mentions'])) {
            $allowed_users = array();
            foreach ($message['mentions'] as $mention) $allowed_users[] = $mention['id'];
            $allowed_mentions['parse'] = array('roles', 'everyone');
            $allowed_mentions['users'] = $allowed_users;
            $builder->setAllowedMentions($allowed_mentions);
        }
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

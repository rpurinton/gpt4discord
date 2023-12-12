<?php

namespace RPurinton\GPT4discord;

class Log extends \Monolog\Logger
{
    public function __construct(private string $myName)
    {
        parent::__construct($myName);
    }
}

<?php

namespace RPurinton\GPT4discord;

class Config
{
    public static function get(string $file): mixed
    {
        $config = json_decode(file_get_contents(__DIR__ . "/../config/$file.json"), true);
        if (!$config) throw new Error("Failed to load config file: $file.json");
        return $config;
    }
}

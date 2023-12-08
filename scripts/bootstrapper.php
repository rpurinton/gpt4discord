#!/usr/bin/env php
<?php

namespace RPurinton\Framework2;

use RPurinton\Framework2\Error;
use RPurinton\Framework2\Consumers\Bootstrapper;

$worker_id = $argv[1] ?? 0;

// enable all errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    require_once __DIR__ . "/../Composer.php";
    $log = LogFactory::create("bootstrapper-$worker_id") or throw new Error("failed to create log");
    set_exception_handler(function ($e) use ($log) {
        $log->debug($e->getMessage(), ["trace" => $e->getTrace()]);
        $log->error($e->getMessage());
        exit(1);
    });
} catch (\Exception $e) {
    echo ("Fatal Exception " . $e->getMessage() . "\n");
    exit(1);
} catch (\Throwable $e) {
    echo ("Fatal Throwable " . $e->getMessage() . "\n");
    exit(1);
} catch (\Error $e) {
    echo ("Fatal Error " . $e->getMessage() . "\n");
    exit(1);
}

$bs = new Bootstrapper($log) or throw new Error("failed to create Bootstrapper");
$bs->init() or throw new Error("failed to initialize Bootstrapper");

<?php
require 'vendor/autoload.php';

use Revolt\EventLoop;

// DATABASE_HOST="10.1.2.30"
// DATABASE_PORT="5432"
// DATABASE_NAME="tools"
// DATABASE_USER="dev"
// DATABASE_PASS="1Zw23wVY6mJdGxxT7kgaRb7U"
// Listen for notifications
$pgAsyncClient = new PgAsync\Client([
    "host" => "10.1.2.30",
    "port" => 5432,
    "database" => "tools",
    "user" => "dev",
    "password" => "1Zw23wVY6mJdGxxT7kgaRb7U"
]);

$pgAsyncClient->listen('new_chat_message')
    ->subscribe(function (\PgAsync\Message\NotificationResponse $message) {
        echo $message->getChannelName() . ': ' . $message->getPayload() . "\n";
    });

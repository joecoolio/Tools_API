<?php
use Dotenv\Dotenv;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

require 'vendor/autoload.php';

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

$ws_worker = new Worker("websocket://0.0.0.0:2346");
$ws_worker->connections = [];

// On connect
$ws_worker->onConnect = function (TcpConnection $connection) use ($ws_worker) {
    echo "New connection: {$connection->id}\n";
    $ws_worker->connections[$connection->id] = $connection;
};

// On disconnect
$ws_worker->onClose = function (TcpConnection $connection) use ($ws_worker) {
    echo "Connection closed: {$connection->id}\n";
    unset($ws_worker->connections[$connection->id]);
};

// On worker start, set up Postgres LISTEN
$ws_worker->onWorkerStart = function($worker) {
    $dbInfo = sprintf("host=%s port=%s dbname=%s user=%s password=%s sslmode=require",
        $_ENV['DATABASE_HOST'],
        $_ENV['DATABASE_PORT'],
        $_ENV['DATABASE_NAME'],
        $_ENV['DATABASE_USER'],
        $_ENV['DATABASE_PASS']
    );

    $conn = pg_connect($dbInfo);
    if (!$conn) {
        throw new \RuntimeException("Could not connect to PostgreSQL");
    }

    // Subscribe to notifications
    pg_query($conn, "LISTEN new_chat_message");

    // Poll for notifications every 0.5 seconds
    Timer::add(0.5, function() use ($conn, $worker) {
        while ($notify = pg_get_notify($conn, PGSQL_ASSOC)) {
            $payload = $notify['payload'];
            echo "Got notification: $payload\n";
            foreach ($worker->connections as $client) {
                $client->send($payload);
            }
        }
        pg_flush($conn);
    });
};

Worker::runAll();

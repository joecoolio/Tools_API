<?php
require 'vendor/autoload.php';

use App\Chat\Responder\NewMessageSender;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use Workerman\Timer;
use Dotenv\Dotenv;
use Predis\Client;

use App\Chat\Responder\Responder;
use App\Chat\Responder\IdentifyResponder;
use App\Chat\Responder\PingResponder;
use App\Chat\Responder\SendMessageResponder;
use App\Chat\Responder\GetChatsResponder;
use App\Chat\Responder\GetMessagesInChatResponder;
use App\Chat\Responder\MarkMessageReadResponder;

use App\Util;
use App\Chat\ChatUtil;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

// Stateless responder map
$responderMap = [
    "identify" => IdentifyResponder::class,
    "ping" => PingResponder::class,
    "send_message" => SendMessageResponder::class,
    "get_chats" => GetChatsResponder::class,
    "get_messages" => GetMessagesInChatResponder::class,
    "mark_message_read" => MarkMessageReadResponder::class,
];

$redis = Util::getRedisConnection();

$ws_worker = new Worker("websocket://0.0.0.0:8080");
$ws_worker->connections = [];

// PostgreSQL connection (async)
$dbInfo = sprintf("host=%s port=%s dbname=%s user=%s password=%s sslmode=require",
    $_ENV['DATABASE_HOST'],
    $_ENV['DATABASE_PORT'],
    $_ENV['DATABASE_NAME'],
    $_ENV['DATABASE_USER'],
    $_ENV['DATABASE_PASS']
);

// PostgreSQL Connection for async commands
$pgAsyncConnection = pg_connect($dbInfo);

// Issue LISTEN commands (async send)
// Listen for new chat messages to arrive
pg_send_query($pgAsyncConnection, "LISTEN new_chat_message");
pg_get_result($pgAsyncConnection); // clear result

// Promise-style wrapper for LISTEN/NOTIFY
function pgListenAsync($conn, callable $onNotify){
    $fd = pg_socket($conn);
    $loop = Worker::getEventLoop();

    // Watch for data from PostgreSQL
    $loop->onReadable($fd, function () use ($conn, $onNotify) {
        // Clear any pending results
        while ($res = pg_get_result($conn)) {
            pg_free_result($res);
        }

        // Fetch all notifications
        while ($notify = pg_get_notify($conn, PGSQL_ASSOC)) {
            $onNotify($notify);
        }
    });
}

$isIdentified = function(TcpConnection $connection) use ($redis): bool {
    $key = "CHAT-CONNID-TO-NID-" . $connection->id;
    return $redis->exists($key);
};

// On worker start, set up Postgres LISTEN
$ws_worker->onWorkerStart = function($worker) use ($redis, $pgAsyncConnection) {
    // Clean out redis on startup
    $keys = $redis->keys('CHAT-CONNID-TO-NID-*');
    if ($keys) $redis->del($keys);
    $keys = $redis->keys('CHAT-NID-TO-CONNID-*');
    if ($keys) $redis->del($keys);

    // Register handler for notifications
    pgListenAsync($pgAsyncConnection, function ($notify) use($worker) {
        $payload = $notify['payload'];
        echo "Got notification: $payload\n";

        // The sender that will send the messages down below
        $responder = new NewMessageSender();

        // Send the message to all involved neighbors
        $data = json_decode($payload, true);
        foreach ($data['neighbors'] as $neighbor) {
            $neighborId = $neighbor['neighbor_id'];
            // Lookup the connection ID(s) for the neighbor 
            foreach (ChatUtil::getConnectionIdsforNID($neighborId) as $clientId) {

                // Get the connection for the given ID
                $result = array_filter($worker->connections, fn($c) => $c->id === $clientId);
                $client = reset($result);

                if ($client != false) {
                    // Send the new message to the connection
                    echo "Sending new message to neighbor {$neighborId} @ conn: {$client->id}\n";
                    $response = $responder->respond($client, [ "msg_id" => $data['message']['id'] ]);
                    $client->send(json_encode($response));
                }
            }
        }
    });
};

// On connect
$ws_worker->onConnect = function (TcpConnection $connection) use ($ws_worker) {
    echo "New connection! ({$connection->id}) from IP {$connection->getRemoteIp()}\n";
    $ws_worker->connections[$connection->id] = $connection;
};

// On disconnect
$ws_worker->onClose = function (TcpConnection $connection) use ($ws_worker, $redis) {
    echo "Connection closed: {$connection->id}\n";
    unset($ws_worker->connections[$connection->id]);

    // Update last seen date on neighbor to reflect when they logged off
    $key = "CHAT-WMID-TO-NID-" . $connection->id;
    $neighborId = $redis->get($key);

    if ($neighborId != null) {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            update neighbor set chat_last_seen = now()
            where id = :me
        ");
        $stmt->execute(params: [
            ":me" => $neighborId
        ]);

        // Remove the neighbor mapping
        $redis->del($key);
    }
};

$ws_worker->onMessage = function (TcpConnection $connection, string $msg) use ($isIdentified, $responderMap): void {
    echo "Incoming[{$connection->id}]: $msg\n";

    $dataArray = json_decode($msg, true);

    if (isset($dataArray['type'])) {
        $type = $dataArray['type'];
        $responderClass = $responderMap[$type] ?? null;
        $response = null;

        try {
            if ($responderClass != null) {
                $responder = new $responderClass();
                if ($responder instanceof Responder) {
                    if (! $responder->identificationRequired() || $isIdentified($connection)) {
                        $response = $responder->respond($connection, $dataArray);
                    }
                }
            }
            // Fallback if we don't know how to process the type
            if ($response == null) {
                $response = [
                    "type" => "unsupported",
                    "neighborId" => ChatUtil::getNIDforConnId($connection->id),
                ];
            }
        } catch (Exception $e) {
            error_log("Chat Exception: " . $e);
            $response = [
                "type" => "error",
                "message_uuid" => $dataArray["message_uuid"],
                "exception" => $e,
            ];
        }

        echo "Outgoing[{$connection->id}]: " . json_encode($response) . "\n";
        $connection->send(json_encode($response));
    }
};

$ws_worker->onError = function (TcpConnection $connection, int $code, string $msg): void {
    echo "Error[{$connection->id}]: $code - $msg\n";
};

// Run server
Worker::runAll();

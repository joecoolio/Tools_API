<?php
require 'vendor/autoload.php';

use Workerman\Connection\TcpConnection;
use Workerman\Worker;
use Dotenv\Dotenv;
use Predis\Client;

use App\Chat\Responder\Responder;
use App\Chat\Responder\IdentifyResponder;
use App\Chat\Responder\PingResponder;
use App\Chat\Responder\SendMessageResponder;
use App\Chat\Responder\GetChatsResponder;
use App\Chat\Responder\GetMessagesInChatResponder;

use App\Util;
use App\Chat\ChatUtil;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

class Chatserver {
    // The websocket worker
    protected Worker $worker;    

    // Redis client
    protected Client $redis;

    // Stateless responder map
    protected $responderMap = [
        "identify" => IdentifyResponder::class,
        "ping" => PingResponder::class,
        "send_message" => SendMessageResponder::class,
        "get_chats" => GetChatsResponder::class,
        "get_messages" => GetMessagesInChatResponder::class,
    ];

    public function __construct(string $address)
    {
        $this->worker = new Worker($address);
        $this->worker->count = 1;

        $this->worker->onConnect = [$this, 'handleConnect'];
        $this->worker->onMessage = [$this, 'handleMessage'];
        $this->worker->onClose   = [$this, 'handleClose'];
        $this->worker->onError   = [$this, 'handleError'];

        $this->redis = Util::getRedisConnection();
    }

    public function run() {
        Worker::runAll();
    }

    public function handleConnect(TcpConnection $connection) {
        echo "New connection! ({$connection->id}) from IP {$connection->getRemoteIp()}\n";
    }

    public function handleMessage(TcpConnection $connection, string $msg) {
        echo "Incoming[{$connection->id}]: $msg\n";

        $dataArray = json_decode($msg, true);

        if (isset($dataArray['type'])) {
            $type = $dataArray['type'];
            $responderClass = $this->responderMap[$type] ?? null;
            $response = null;

            try {
                if ($responderClass != null) {
                    $responder = new $responderClass();
                    if ($responder instanceof Responder) {
                        if (! $responder->identificationRequired() || $this->isIdentified($connection)) {
                            $response = $responder->respond($connection, $dataArray);
                        }
                    }
                }
                // Fallback if we don't know how to process the type
                if ($response == null) {
                    $response = [
                        "type" => "unsupported",
                        "neighborId" => ChatUtil::getNIDforResourceId($connection->id),
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
    }

    public function handleClose(TcpConnection $connection) {
        echo "Connection {$connection->id} has disconnected\n";

        // Update last seen date on neighbor to reflect when they logged off
        $key = "CHAT-WMID-TO-NID-" . $connection->id;
        $neighborId = $this->redis->get($key);

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
            $this->redis->del($key);
        }
    }

    public function handleError(TcpConnection $connection, int $code, string $msg) {
        echo "Error[{$connection->id}]: $code - $msg\n";
    }
    
    protected function isIdentified(TcpConnection $connection): bool {
        $key = "CHAT-WMID-TO-NID-" . $connection->id;
        return $this->redis->exists($key);
    }
}


// Create and run server
$server = new Chatserver("websocket://0.0.0.0:8080");
$server->run();

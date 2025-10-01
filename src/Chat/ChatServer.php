<?php

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;
use Amp\Http\Server\SocketHttpServer;
use Amp\Http\Server\StaticContent\DocumentRoot;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientGateway;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\Server\WebsocketGateway;
use Amp\Websocket\WebsocketClient;
use App\Chat\ChatUtil;
use App\Util;
use Dotenv\Dotenv;
use Monolog\Logger;
use function Amp\ByteStream\getStdout;
use function Amp\delay;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use function Amp\async;

use App\Chat\Responder\Responder;
use App\Chat\Responder\IdentifyResponder;
use App\Chat\Responder\PingResponder;
use App\Chat\Responder\SendMessageResponder;
use App\Chat\Responder\GetChatsResponder;
use App\Chat\Responder\GetMessagesInChatResponder;
use App\Chat\Responder\MarkMessageReadResponder;
use App\Chat\Responder\GetChatResponder;
use App\Chat\Responder\NewMessageSender;

require __DIR__ . '/../../vendor/autoload.php';

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
$dotenv->load();

// Stateless responder map
// The keys are message type values sent from clients.  Then it's handled by the class provided.
$responderMap = [
    "identify" => IdentifyResponder::class,                 // Identify yourself to the server
    "ping" => PingResponder::class,                         // Ping to keep the connection alive
    "send_message" => SendMessageResponder::class,          // Send a message
    "get_chats" => GetChatsResponder::class,                // Get all chats involving me
    "get_chat" => GetChatResponder::class,                  // Get a single chat (involving me)
    "get_messages" => GetMessagesInChatResponder::class,    // Get all messages in a chat
    "mark_message_read" => MarkMessageReadResponder::class, // Mark a message as read
];

// Setup redis
$redis = Util::getRedisConnection();

// Setup PostgreSQL connection pool
$dbInfo = sprintf("host=%s port=%s dbname=%s user=%s password=%s sslmode=require",
    $_ENV['DATABASE_HOST'],
    $_ENV['DATABASE_PORT'],
    $_ENV['DATABASE_NAME'],
    $_ENV['DATABASE_USER'],
    $_ENV['DATABASE_PASS']
);
$config = PostgresConfig::fromString($dbInfo);
$postgresConnectionPool = new PostgresConnectionPool($config);

// Clean out redis on startup
// These are keys created by chat/identify that are specific to connections.
// CHAT-CONNID-TO-NID-x -> a (websocket cilent id -> neighbor id) map where x is the client id (1-to-1)
// CHAT-NID-TO-CONNID-x -> a (neighbor id -> websocket client id[]) map where x is the neighbor id (1-to-many)
$keys = $redis->keys('CHAT-CONNID-TO-NID-*');
if ($keys) $redis->del($keys);
$keys = $redis->keys('CHAT-NID-TO-CONNID-*');
if ($keys) $redis->del($keys);

// Setup Amp socket server and associated objects
$logHandler = new StreamHandler(getStdout());
$logHandler->setFormatter(new ConsoleFormatter());
$logger = new Logger('server');
$logger->pushHandler($logHandler);
$server = SocketHttpServer::createForDirectAccess($logger);

// Listen on IPv4 and v6
$server->expose(new Socket\InternetAddress('0.0.0.0', $_ENV['CHAT_SERVER_PORT']));
$server->expose(new Socket\InternetAddress('[::]', $_ENV['CHAT_SERVER_PORT']));

$errorHandler = new DefaultErrorHandler();
$acceptor = new Rfc6455Acceptor();

$clientHandler = new class implements WebsocketClientHandler {
    // Helper function to determine if a client has identified themselves
    private function isIdentified(WebsocketClient $client): bool {
        global $redis;

        $key = "CHAT-CONNID-TO-NID-" . $client->getId();
        return $redis->exists($key);
    }

    public function __construct(
        private readonly WebsocketGateway $allClients = new WebsocketClientGateway(),
    ) {
        // Listen to the 'new_chat_message' channel from postgresql.
        // When a new message arrives, send it to all involved parties.
        async(function () use ($allClients)  {
            global $postgresConnectionPool;
            global $logger;

            $channel_name = 'new_chat_message';
            $listener = $postgresConnectionPool->listen($channel_name);
            $logger->debug(message: sprintf("Listening on channel: %s", $channel_name));
            foreach ($listener as $notification) {
                $logger->debug(sprintf("Notification (%s): %s", $notification->channel, $notification->payload));

                // The sender that will send the messages down below
                $responder = new NewMessageSender();

                // Send the message to all involved neighbors
                $data = json_decode($notification->payload, true);

                foreach ($data['neighbors'] as $neighbor) {
                    $neighborId = $neighbor['neighbor_id'];
                    // Lookup the connection ID(s) for the neighbor 
                    foreach (ChatUtil::getConnectionIdsforNID($neighborId) as $clientId) {

                        // Get the connection for the given ID
                        $result = array_filter($allClients->getClients(), fn($c) => $c->getId() === $clientId);
                        $client = reset($result);

                        if ($client != false) {
                            // Send the new message to the connection
                            $logger->debug(sprintf("Notification: sending new message to neighbor (%d) @ client (%d)", $neighborId, $client->getId()));
                            $response = $responder->respond($client, $postgresConnectionPool, [ "msg_id" => $data['message']['id'] ]);
                            $client->sendText(json_encode($response));
                        }
                    }
                }
            }
        });
    }

    public function handleClient(
        WebsocketClient $client,
        Request $request,
        Response $response,
    ): void {
        global $postgresConnectionPool;
        global $redis;
        global $logger;
        global $responderMap;
        
        // Add to list of all clients
        $this->allClients->addClient($client);

        // New connection
        $logger->info(sprintf("New connection (%d) from IP %s", $client->getId(), $client->getRemoteAddress()));

        // Loop for receiving/sending messages
        foreach ($client as $payload) {
            $msg = $payload->buffer();
            $dataArray = json_decode($msg, true);

            if (isset($dataArray['type'])) {
                $type = $dataArray['type'];
                if ($type != "ping")
                    $logger->debug(message: sprintf("Incoming (%d): %s", $client->getId(), $msg));

                $responderClass = $responderMap[$type] ?? null;
                $response = null;

                try {
                    if ($responderClass != null) {
                        $responder = new $responderClass();
                        if ($responder instanceof Responder) {
                            if (! $responder->identificationRequired() || $this->isIdentified($client)) {
                                $response = $responder->respond($client, $postgresConnectionPool, $dataArray);
                            }
                        }
                    }
                    // Fallback if we don't know how to process the type
                    if ($response == null) {
                        $response = [
                            "type" => "unsupported",
                            "neighborId" => ChatUtil::getNIDforConnId($client->getId()),
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

                if ($type != "ping")
                    $logger->debug(message: sprintf("Outgoing (%d): %s", $client->getId(), json_encode($response)));

                $client->sendText(json_encode($response));
            }
        }

        // When connection drops, this runs
        $logger->debug(message: sprintf("Connection closed: %d", $client->getId()));

        // Update last seen date on neighbor to reflect when they logged off
        $key = "CHAT-CONNID-TO-NID-" . $client->getId();
        $neighborId = $redis->get($key);

        if ($neighborId != null) {
            $stmt = $postgresConnectionPool->prepare("
                update neighbor set chat_last_seen = now()
                where id = :me
            ");
            $stmt->execute(params: [
                ":me" => $neighborId
            ]);

            // Remove the (conn -> neighbor) mapping
            $redis->del($key);
        }

        // Remove this client from the (neighbor -> client) 1-to-many map
        $ntocKey = $key = "CHAT-NID-TO-CONNID-" . $client->getId();
        $ntoc = $redis->get($key);
        if ($ntoc != null) {
            $array = json_decode($ntoc, true);
            unset($array[$client->getId()]);
            $redis->set($ntocKey, json_encode($array));
        }
    }
};

$websocket = new Websocket($server, $logger, $acceptor, $clientHandler);

$router = new Router($server, $logger, $errorHandler);
$router->addRoute('GET', '/chat', $websocket);
$router->setFallback(new DocumentRoot($server, $errorHandler, __DIR__));

$server->start($router, $errorHandler);


// For windows that can't use trapSignal
// Just keep the server running forever
// This must be async to avoid freezing the whole system
while (true) delay(100);

// Await SIGINT or SIGTERM to be received.
// $signal = trapSignal([SIGINT, SIGTERM]);
// $logger->info(sprintf("Received signal %d, stopping socket server", $signal));
// $server->stop();

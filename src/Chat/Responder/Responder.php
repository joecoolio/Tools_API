<?php

namespace App\Chat\Responder;
use App\Util;
use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

// Called by the chat server to handle a specific type of message.
abstract class Responder {

    // This is what's run when a request of the appropriate type arrives.
    abstract public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array;

    // Does this class require that the user is already identified.
    // If true, it won't be run unless id is already done.
    public function identificationRequired(): bool {
        return false;
    }

    // Get the neighbor ID that corresponds to this connection.
    // Only works if identification is already done.
    public static function getMyNeighborId(WebsocketClient $client): int {
        $redis = Util::getRedisConnection();
        $key = "CHAT-CONNID-TO-NID-" . $client->getId();
        $neighborId = $redis->get($key);
        if ($neighborId) {
            return (int) $neighborId;
        } else {
            throw new \Exception("Cannot determine neighbor ID for connection " . $client->getId());
        }
    }
}

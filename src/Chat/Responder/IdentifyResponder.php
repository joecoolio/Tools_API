<?php
namespace App\Chat\Responder;

use App\Util;
use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class IdentifyResponder extends Responder {
    
    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        $jwt = Util::validateJWT($request['token'], false);

        if ($jwt) {
            $redis = Util::getRedisConnection();

            // Store chat connection ID → neighbor ID (1-to-1)
            $key = "CHAT-CONNID-TO-NID-" . $client->getId();
            $redis->set($key, $jwt['neighborId']);
            // Store neighbor ID → chat connection IDs (1-to-many)
            $key = "CHAT-NID-TO-CONNID-" . $jwt['neighborId'];
            $existing = $redis->get($key);
            $array = $existing ? json_decode($existing, true) : [];
            $array[] = $client->getId();
            $redis->set($key, json_encode($array));

            // // Get last_seen date for neighbor
            $stmt = $dbConnPool->prepare("
                select chat_last_seen from neighbor where id = :me
            ");
            $result = $stmt->execute(params: [
                "me" => $jwt['neighborId']
            ]);
            foreach ($result as $row) {
                // $row is an associative-array of column values, e.g.: $row['column_name']
                $oldLastSeenTz = $row['chat_last_seen'];
            }

            return [
                "type" => "identify_result",
                "last_seen" => $oldLastSeenTz,
                "result" => true
            ];
        }

        return [
            "type" => "identify_result",
            "result" => false
        ];
    }
}

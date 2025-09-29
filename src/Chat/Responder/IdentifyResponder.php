<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class IdentifyResponder extends Responder {

    public function respond(TcpConnection $connection, array $request): array {
        $jwt = Util::validateJWT($request['token'], false);

        if ($jwt) {
            $redis = Util::getRedisConnection();

            // Store chat connection ID → neighbor ID (1-to-1)
            $key = "CHAT-CONNID-TO-NID-" . $connection->id;
            $redis->set($key, $jwt['neighborId']);
            // Store neighbor ID → chat connection IDs (1-to-many)
            $key = "CHAT-NID-TO-CONNID-" . $jwt['neighborId'];
            $existing = $redis->get($key);
            $array = $existing ? json_decode($existing, true) : [];
            $array[] = $connection->id;
            $redis->set($key, json_encode($array));

            // Get last_seen date for neighbor
            $pdo = Util::getDbConnection();
            $stmt = $pdo->prepare("
                select chat_last_seen from neighbor where id = :me
            ");
            $stmt->execute(params: [
                ":me" => $jwt['neighborId']
            ]);
            $oldLastSeenTz = $stmt->fetchColumn();

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

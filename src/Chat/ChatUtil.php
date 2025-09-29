<?php

namespace App\Chat;
use App\Util;

abstract class ChatUtil {
    // Given a connection ID from the server, get the neighbor ID.
    // This is populated by IdentifyResponder when it receives a JWT with ID info in it.
    public static function getNIDforConnId(int $resourceId): int|null {
        $redis = Util::getRedisConnection();

        $key = "CHAT-CONNID-TO-NID-" . $resourceId;
        $neighborId = $redis->get($key);
        return (int) $neighborId;
    }

    // Given a neighbor ID, get all connection IDs.
    public static function getConnectionIdsforNID(int $neighborId): array {
        $redis = Util::getRedisConnection();

        $key = "CHAT-NID-TO-CONNID-" . $neighborId;
        $existing = $redis->get($key);
        $array = $existing ? json_decode($existing, true) : [];
        return $array;
    }
}

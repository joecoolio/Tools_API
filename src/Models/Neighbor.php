<?php

namespace App\Models;

use \PDO;
use \App\Util;

class Neighbor extends BaseModel {
    // Get all the neighbors and how far away each one is
    // Make sure User.getFriends() has been called so redis is populated
    public function listAllNeighbors(int $neighborId): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            with me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                f.id,
                f.name,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f
                inner join me
                    on f.id != me.id
        ');

        $stmt->execute(params: [ ':neighborId' => $neighborId ]);
        $neighbors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get friends from redis
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$neighborId-friends";
        $friends = json_decode($redis->get($redisFriendKey));

        // Tag each neighbor as a friend or not
        foreach ($neighbors as &$neighbor) {
            $neighbor['is_friend'] = in_array($neighbor['id'], $friends);
        }

        return json_encode($neighbors );
    }
}
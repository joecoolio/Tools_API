<?php

namespace App\Models;

use \PDO;
use \App\Util;

class User extends BaseModel {
    // Get my info
    public function getInfo(int $neighborId) {
        $pdo = Util::getDbConnection();

        $sql = "
            select
                home_address,
                photo_link,
                ST_Y(home_address_point::geometry) AS latitude,
                ST_X(home_address_point::geometry) AS longitude	
            from neighbor
            where id = :neighborId;
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(params: [
            ':neighborId' => $neighborId
        ]);
        $results = $stmt->fetch(PDO::FETCH_ASSOC);

        return json_encode($results);
    }

    // Get all of your neighbor linkages + the referenced neighbors
    // When this runs, it puts the list of your friends in redis
    public function reloadFriends(int $neighborId): string {
        $pdo = Util::getDbConnection();
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$neighborId-friends";

        // Remove the item from redis
        $redis->del($redisFriendKey);

        $sql = "
            WITH RECURSIVE friend_of_friend AS (
                SELECT friend_id, ARRAY[]::integer[] via, 1 depth
                FROM friendship
                WHERE neighbor_id = :neighborId
                
                UNION
                
                SELECT f.friend_id, fof.via || f.neighbor_id via, fof.depth + 1 depth
                FROM friendship f
                JOIN friend_of_friend fof
                    ON f.neighbor_id = fof.friend_id
            ), numbered as (
                select
                    fof.*,
                    row_number() over (partition by fof.friend_id order by depth) rn
                from
                    friend_of_friend fof
            ), me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                nf.friend_id,
                nf.via,
                nf.depth,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                numbered nf
                inner join neighbor f
                    on nf.friend_id = f.id
                inner join me 
                    on :radius_m = :radius_m
            where rn = 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(params: [
            ':neighborId' => $neighborId,
            ':radius_m' => 3000
        ]);
        $friend_results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get each distinct neighborId into an array
        // $neighborIds = [];
        // foreach ($friend_results as $friend) {
            // array_push($neighborIds, $friend['friend_id']);
        // }

        // Store friends to redis - the proximity filter can then use this for filters later
        // $redis->set($redisFriendKey, json_encode($neighborIds));
        // Store friends as {id: <friend>} in an associative array
        $assocArray = [];
        foreach ($friend_results as $friend) {
            if (isset($friend['friend_id'])) {
                $assocArray[$friend['friend_id']] = $friend;
            }
        }

        $redis->set($redisFriendKey, json_encode($assocArray));

        return "success";
    }

    // Get all of your friends
    // Level 1 are direct friends, level 2 are friends of them, etc.
    // If friends haven't been retrieved, that happens automatically.
    public function getFriends(int $neighborId, int $level = 999): string {
        $pdo = Util::getDbConnection();
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$neighborId-friends";

        // Remove the item from redis
        if (!$redis->exists($redisFriendKey)) {
            $this->reloadFriends($neighborId);
        }

        $friends = json_decode($redis->get($redisFriendKey), true);

        // Filter friends by depth
        $friends = array_filter($friends, fn($item) => $item['depth'] <= $level);

        // Get the neighbor objects that correspond to all the friends
        $sql = "
            with me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                f.id,
                f.name,
                f.home_address,
                f.photo_link,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f
                inner join me
                    on f.id != me.id
            where f.id = any(:friendIds)
        ";
        $stmt = $pdo->prepare($sql);

        // Get all the friend IDs as an array
        $neighborIds = array_column($friends,"friend_id");

        $pgArrayString = '{' . implode(',', $neighborIds) . '}';
        $stmt->execute([ 
            ':neighborId' => $neighborId,
            ':friendIds' => $pgArrayString
        ]);
        $neighbors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Add the friend level to the results by looking it up
        foreach ($neighbors as &$neighbor) {
            $neighbor['depth'] = $friends[$neighbor['id']]['depth'];
        }

        return json_encode($neighbors);
    }

}

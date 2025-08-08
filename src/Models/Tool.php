<?php

namespace App\Models;

use \PDO;
use \App\Util;

class Tool extends BaseModel {
    // Get all the tools that I currently own
    public function listMyTools(int $neighborId): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('select id, name, product_url, replacement_cost from tool where owner_id = ?');

        $stmt->execute(params: [ $neighborId ]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode($tools );
    }

    // Get all the tools available to a neighbor
    // This gets the list of friends from redis and filters based on that
    public function listAllTools(int $neighborId): string {
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$neighborId-friends";

        if ($redis->exists($redisFriendKey)) {
            $neighborIds = json_decode($redis->get($redisFriendKey));
            $pdo = Util::getDbConnection();
            $sql = "
                with me as (
                    select id, home_address_point from neighbor where id = :neighborId
                )
                select
                    t.id,
                    t.owner_id,
                    t.name,
                    t.product_url,
                    t.replacement_cost,
                    c.name category,
                    c.icon category_icon,
                    ST_Y(f.home_address_point::geometry) AS latitude,
                    ST_X(f.home_address_point::geometry) AS longitude,
                    ST_Distance(me.home_address_point, f.home_address_point) distance_m
                from
                    tool t
                    inner join tool_category c
                    	on t.category = c.id
                    inner join neighbor f
                        on t.owner_id = f.id
                    inner join me
                        on f.id != me.id
                where t.owner_id = any(:friendIds)
            ";
            $stmt = $pdo->prepare($sql);
            $pgArrayString = '{' . implode(',', $neighborIds) . '}';

            $stmt->execute(params: [
                ':neighborId' => $neighborId,
                ':friendIds' => $pgArrayString ]
            );
            $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return json_encode($tools );
        } else {
            return json_encode([]);
        }
    }
}
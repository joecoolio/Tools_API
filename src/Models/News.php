<?php

namespace App\Models;

use \PDO;
use \App\Util;


class News extends BaseModel {
    // Get all news items or the subset after a specified id
    public function getNews(int $myNeighborId, ?float $radiusMiles = 2 /* 2 miles default */, int $afterId = 0 /* default to all */, int $beforeId = 99999999 /* default to all */, int $maxItems = 10): array {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            with me as (
                select id, home_address_point from neighbor where id = :myNeighborId
            )
            select
                n.id,
                n.type,
                n.occur_ts,
                n.neighbor_id,
                n.tool_id,
                ST_Distance(me.home_address_point, n.occur_point) distance_m
            from
                news n,
                me
            where
                n.neighbor_id != me.id
                and ST_DWithin(
                    n.occur_point,
                    me.home_address_point,
                    :radius
                )
                and n.id > :afterId
                and n.id < :beforeId
            order by
                n.occur_ts desc,
                n.id desc
            limit :maxItems
        ');
        $stmt->execute(params: [
            ':myNeighborId' => $myNeighborId,
            ':radius' => $radiusMiles * 1.60934 * 1000,
            ':afterId' => $afterId,
            ':beforeId' => $beforeId,
            ':maxItems' => $maxItems
        ]);
        $news = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // If tool is provided, then the related neighbor must be your friend.
        // You cannot see any tools from non-friends.
        $friends = Util::getFriends($myNeighborId);
        $filtered = array_filter($news, function($newsItem) use ($friends) {
            return $newsItem['tool_id'] == null || array_key_exists($newsItem['neighbor_id'], $friends);
        });

        return $filtered;
    }

}
<?php

namespace App\Models;

use \PDO;
use \App\Util;

class Neighbor extends BaseModel {
    // Get all the neighbors and how far away each one is
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
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode($tools );
    }
}
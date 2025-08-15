<?php

namespace App\Models;

use \PDO;
use \App\Util;
use Psr\Http\Message\ResponseInterface as Response;
use \Slim\Psr7\Stream;


class Neighbor extends BaseModel {
    // Get a neighbor's details
    public function getNeighbor(int $neighborId, int $myNeighborId): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            with me as (
                select id, home_address_point from neighbor where id = :myNeighborId
            )
            select
                f.id,
                f.name,
                f.photo_link,
                f.home_address,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f,
                me
            where
                f.id = :neighborId
        ');

        $stmt->execute(params: [ ':neighborId' => $neighborId, ':myNeighborId' => $myNeighborId ]);
        $neighbor = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get friends from redis
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$myNeighborId-friends";
        $friends = json_decode($redis->get($redisFriendKey));

        // Tag as a friend or not
        $neighbor['is_friend'] = in_array($neighbor['id'], $friends);

        return json_encode($neighbor );
    }

    public function getPhoto(string $photo_id, Response $response): Response {
        $dir = dirname(__DIR__) . "/../images/";
        $filename = $dir . $photo_id;
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $mimetype = Util::getMimeType($ext);
        $fh = fopen($filename, 'rb');
        $stream = new Stream($fh);

        return $response
            ->withHeader('Content-Type', $mimetype)
            ->withHeader('Content-Transfer-Encoding', 'Binary')
            ->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"')
            ->withHeader('Content-Length', filesize($filename))
            ->withHeader('Expires', '0')
            ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
            ->withHeader('Pragma', 'public')
            ->withBody($stream);
    }

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
<?php

namespace App\Models;

use \PDO;
use \App\Util;
use Psr\Http\Message\ResponseInterface as Response;
use Rakit\Validation\Rules\Boolean;
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
                f.nickname,
                f.nickname,
                f.photo_link,
                f.home_address,
                f.tool_count,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
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
        $friends = json_decode($redis->get($redisFriendKey), true);

        // Tag as a friend or not
        if (array_key_exists($neighbor['id'], $friends)) {
            $neighbor['is_friend'] = true;
            $neighbor['depth'] = $friends[$neighbor['id']]['depth']; // Friend depth
        } else {
            $neighbor['is_friend'] = false;
            $neighbor['name'] = $neighbor['nickname']; // Show nicknames on non-friends
            $neighbor['tool_count'] = 0; // Do not show tool count for non-friends
        }
        unset($neighbor['nickname']); // Don't send the separate nickname

        return json_encode($neighbor );
    }

    public function getPhoto(string $photo_id, Response $response): Response {
        $dir = dirname(__DIR__) . "/../images/";
        $filename = $dir . $photo_id;

        if (file_exists($filename)) {
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
        } else {
            $response->getBody()->write(json_encode("File does not exist: $photo_id"));
            return $response->withStatus(404);
        }
    }

    // Get all the neighbors and how far away each one is
    // Make sure User.getFriends() has been called so redis is populated
    public function listAllNeighbors(int $neighborId, int $radius_miles = 9999): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            with me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                f.id,
                f.name,
                f.nickname,
                f.photo_link,
                f.home_address,
                f.tool_count,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f
                inner join me
                    on f.id != me.id
            where
                ST_DWithin(
                    f.home_address_point,
                    me.home_address_point,
                    :radius
                )
        ');
        $stmt->execute(params: [
            ':neighborId' => $neighborId,
            ':radius' => $radius_miles * 1.60934 * 1000
        ]);
        $neighbors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get friends from redis
        $redis = Util::getRedisConnection();
        $redisFriendKey = "$neighborId-friends";
        $friends = json_decode($redis->get($redisFriendKey), true);
        // Get all the friend IDs as an array
        $friendIds = array_column($friends, "friend_id");

        // Tag each neighbor as a friend or not
        // Add the friend level to the results by looking it up
        foreach ($neighbors as &$neighbor) {
            if (array_key_exists($neighbor['id'], $friends)) {
                $neighbor['is_friend'] = true;
                $neighbor['depth'] = $friends[$neighbor['id']]['depth']; // Friend depth
            } else {
                $neighbor['is_friend'] = false;
                $neighbor['name'] = $neighbor['nickname']; // Show nicknames on non-friends
                $neighbor['tool_count'] = 0; // Do not show tool count for non-friends
            }
            unset($neighbor['nickname']); // Don't send the separate nickname
        }

        return json_encode($neighbors );
    }

    // Create a friendship between 2 users.
    // This is a one-way friendship, it does not add it two-ways.
    public function addFriendship(int $sourceNeighborId, int $targetNeighborId): void {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("            
            insert into friendship (neighbor_id, friend_id)
            values (:source, :target)
            on conflict do nothing
        ");
        $stmt->execute(params: [
            ":source" => $sourceNeighborId,
            ":target" => $targetNeighborId
        ]);
    }

    // Delete a friendship between 2 users.
    public function removeFriendship(int $sourceNeighborId, int $targetNeighborId): void {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            delete from friendship
            where neighbor_id = :source
            and friend_id = :target
        ");
        $stmt->execute(params: [
            ":source" => $sourceNeighborId,
            ":target" => $targetNeighborId
        ]);
    }

}
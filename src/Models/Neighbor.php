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
                f.photo_link,
                f.home_address,
                f.tool_count,
                case when n.from_neighbor is not null then true else false end friendship_requested,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f
                inner join me
                    on 1=1
                left outer join notification n
                    on me.id = n.from_neighbor 
                    and f.id = n.to_neighbor
                    and n.type = \'friend_request\'
                    and n.resolved = false
            where
                f.id = :neighborId
        ');

        $stmt->execute(params: [ ':neighborId' => $neighborId, ':myNeighborId' => $myNeighborId ]);
        $neighbor = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get friends (any depth)
        $friends = Util::getFriends($myNeighborId);

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
                case when n.from_neighbor is not null then true else false end friendship_requested,
                ST_Y(f.home_address_point::geometry) AS latitude,
                ST_X(f.home_address_point::geometry) AS longitude,
                ST_Distance(me.home_address_point, f.home_address_point) distance_m
            from
                neighbor f
                inner join me
                    on f.id != me.id
                left outer join notification n
                    on me.id = n.from_neighbor 
                    and f.id = n.to_neighbor
                    and n.type = \'friend_request\'
                    and n.resolved = false
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

        // Get friends (any depth)
        $friends = Util::getFriends($neighborId);

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

    // Create a friendship request between 2 users.
    // This is a one-way friendship, it does not add it two-ways.
    public function requestFriendship(int $sourceNeighborId, int $targetNeighborId, string $message): void {
        $pdo = Util::getDbConnection();

        // If you're already friends, do nothing

        // Get 1-depth friends
        $friends = Util::getFriends($sourceNeighborId, 1);
        // Filter friends by the requested ID
        $friends = array_filter($friends, fn($item) => $item['friend_id'] == $targetNeighborId);
        // If any friends left, that means you're already friends so do nothing.
        if (count($friends) > 0)
            return;

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $pdo->prepare("
            insert into notification (to_neighbor, from_neighbor, type, message)
            values (:target, :source, 'friend_request', :message)
        ");
        $stmt->execute(params: [
            ":source" => $sourceNeighborId,
            ":target" => $targetNeighborId,
            ":message" => $message
        ]);
    }

    // Delete an existing friendship request.
    public function deleteFriendshipRequest(int $sourceNeighborId, int $targetNeighborId): void {
        $pdo = Util::getDbConnection();

        // Delete the request
        $stmt = $pdo->prepare("
            delete from notification
            where from_neighbot = :source
            and to_neighbor = :target
            and type = 'friend_request'
        ");
        $stmt->execute(params: [
            ":source" => $sourceNeighborId,
            ":target" => $targetNeighborId
        ]);
    }

}
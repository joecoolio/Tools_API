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
                userid,
                name,
                nickname,
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

    // Get all of your friends
    // Level 1 are direct friends, level 2 are friends of them, etc.
    // If friends haven't been retrieved, that happens automatically.
    public function getFriends(int $neighborId, int $level = 999): string {
        $pdo = Util::getDbConnection();

        // Get friends filtered by depth
        $friends = Util::getFriends($neighborId, $level);

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
                f.tool_count,
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
            $neighbor['is_friend'] = true; // Everyone here is your friend
            $friend = array_find($friends, fn($n) => $n['friend_id'] == $neighbor['id']);
            $neighbor['depth'] = $friend['depth'];
        }

        return json_encode($neighbors);
    }

    // Do a geocode lookup of an address to make sure it's useable.
    // Returns true if the geocode worked.
    public function validateAddress(string $address) : bool {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            SELECT ST_SetSRID(ST_SnapToGrid((g).geomout, 0.00001), 4326)
            FROM geocode(:address, 1) AS g
        ");
        $stmt->execute(params: [ ':address' => $address ]);
        return $stmt->rowCount() == 1;
    }

    // Update personal info
    public function updateInfo(
        string $neighborId,
        string $name,
        string $nickname,
        ?string $password,
        string $address,
        $photoFile,
        $uploadDirectory
    ) : void {
        // Upload the file first and get the returned filename
        $filename = null;
        if ($photoFile != null) {
            $filename = (new File())->uploadFile($uploadDirectory, $photoFile);
        }

        // Reset the password next
        $hashedPassword = null;
        if ($password != null) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        }

        // Update all the data
        $data = [
            'name' => $name,
            'nickname' => $nickname,
            'home_address' => $address,
        ];
        if ($filename) $data['photo_link'] = $filename;
        if ($hashedPassword != null) $data['password_hash'] = $hashedPassword;

        // Prep (x=y) fields & placeholders
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        $kvs = array_map(fn($f) => "$f = :$f", $fields);

        // Add neighborid to the data
        $data['neighborId'] = $neighborId;

        // Run an update
        $pdo = Util::getDbConnection();

        $pdo->beginTransaction();
        try {
            $sql =
                "update neighbor set " . 
                implode(', ', $kvs) . " " .
                "where id = :neighborId";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);

            // Update the address point
            $stmt = $pdo->prepare("
                UPDATE neighbor
                    SET home_address_point = (
                        SELECT ST_SetSRID(ST_SnapToGrid((g).geomout, 0.00001), 4326)
                        FROM geocode(home_address, 1) AS g
                    )
                WHERE
                    home_address IS NOT NULL
                    and id = :id
            ");
            $stmt->execute(params: [ ':id' => $neighborId ]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Get all notifications
    public function listNotifications(int $neighborId): array {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            select
                id,
                from_neighbor,
                type,
                message,
                created_ts
            from
                notification
            where
                to_neighbor = :neighborId
                and resolved = false
        ');
        $stmt->execute(params: [
            ':neighborId' => $neighborId,
        ]);
        $reqs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $reqs;
    }

    // Resolve a notification
    public function resolveNotifications(int $notificationId): void {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare('
            update notification
            set resolved = true, resolution_ts = now()
            where id = :notificationId
        ');
        $stmt->execute(params: [
            ':notificationId' => $notificationId,
        ]);
    }

        // Create a friendship.
    // A user can only create friendships to them, not from (those go as requests).
    // So, the other guy is the source and I am the target.
    public function createFriendship(int $myNeighborId, int $otherNeighborId): void {
        $pdo = Util::getDbConnection();

        // Create the friendship.  If there's already a request, just do nothing.
        $stmt = $pdo->prepare("
            insert into friendship (neighbor_id, friend_id)
            values (:otherguy, :me)
            on conflict do nothing
        ");
        $stmt->execute(params: [
            ":otherguy" => $otherNeighborId,
            ":me" => $myNeighborId,
        ]);

        // Send a notification to the other dude that his friend request was accepted.
        $stmt = $pdo->prepare("
            insert into notification (to_neighbor, from_neighbor, type, message)
            select :otherguy, :me, 'system_message', n.name || ' accepted your friend request!'
            from neighbor n
            where id = :me
        ");
        $stmt->execute(params: [
            ":otherguy" => $otherNeighborId,
            ":me" => $myNeighborId,
        ]);

        // The other guy's friends have now changed, drop them from redis.
        Util::expireFriends($otherNeighborId);
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

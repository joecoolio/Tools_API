<?php

namespace App\Models;

use \PDO;
use \App\Util;
use SpomkyLabs\Pki\ASN1\Component\Length;

class Tool extends BaseModel {
    // Get all the tools that I currently own
    public function listMyTools(int $neighborId): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
                select
                    t.id,
                    t.owner_id,
                    t.short_name,
                    t.brand,
                    t.name,
                    t.product_url,
                    t.replacement_cost,
                    c.id category_id,
                    c.name category,
                    c.icon category_icon,
                    t.photo_link
                from
                    tool t
                    inner join tool_category c
                    	on t.category = c.id
                where t.owner_id = ?
        ");

        $stmt->execute(params: [ $neighborId ]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return json_encode($tools );
    }

    // Get all the tools available to a neighbor
    // This gets the list of friends and filters based on that
    public function listAllTools(int $neighborId): array {
        // Get friends
        $friends = Util::getFriends($neighborId);

        // If no friends, no tools :(
        if (count($friends) == 0) {
            return [];
        }

        // Get all the friend IDs as an array
        $neighborIds = array_column($friends,"friend_id");
        $neighborIdsArrayString = '{' . implode(',', $neighborIds) . '}';

        $pdo = Util::getDbConnection();
        $sql = "
            with me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                t.id,
                t.owner_id,
                t.short_name,
                t.brand,
                t.name,
                t.product_url,
                t.replacement_cost,
                c.id category_id,
                c.name category,
                c.icon category_icon,
                t.photo_link,
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

        $stmt->execute(params: [
            ':neighborId' => $neighborId,
            ':friendIds' => $neighborIdsArrayString ]
        );
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $tools;
    }

    // Get info about a single tool
    public function getTool(int $toolId, int $neighborId): string {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            with me as (
                select id, home_address_point from neighbor where id = :neighborId
            )
            select
                t.id,
                t.owner_id,
                t.short_name,
                t.brand,
                t.name,
                t.product_url,
                t.replacement_cost,
                c.id category_id,
                c.name category,
                c.icon category_icon,
                t.photo_link,
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
            where t.id = :toolId
        ");

        $stmt->execute(params: [ ':toolId' => $toolId, ':neighborId' => $neighborId ]);
        $tool = $stmt->fetch(PDO::FETCH_ASSOC);

        return json_encode($tool );
    }

    public function getCategories(): array {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            select
                id,
                name,
                icon
            from
                tool_category c
        ");

        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $categories;
    }

    public function updateTool(
        int $toolId,
        string $neighborId,
        string $shortName,
        string $brand,
        string $name,
        string $productUrl,
        float $replacementCost,
        int $categoryId,
        $photoFile,
        $uploadDirectory
    ): void {
        // Upload the file first and get the returned filename
        $filename = null;
        if ($photoFile != null) {
            $filename = (new File())->uploadFile($uploadDirectory, $photoFile);
        }

        // Update all the data
        $data = [
            'owner_id' => $neighborId,
            'name' => $name,
            'product_url' => $productUrl,
            'replacement_cost' => $replacementCost,
            'category' => $categoryId,
            'short_name' => $shortName,
            'brand' => $brand,
        ];
        if ($filename) $data['photo_link'] = $filename;

        // Prep (x=y) fields & placeholders
        $fields = array_keys($data);
        // $placeholders = array_map(fn($f) => ":$f", $fields);
        $kvs = array_map(fn($f) => "$f = :$f", $fields);

        // Add toolid to the data
        $data['toolId'] = $toolId;

        // Run an update
        $pdo = Util::getDbConnection();

        $sql =
            "update tool set " . 
            implode(', ', $kvs) . " " .
            "where id = :toolId";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
    }

    public function createTool(
        string $neighborId,
        string $shortName,
        string $brand,
        string $name,
        string $productUrl,
        float $replacementCost,
        int $categoryId,
        $photoFile,
        $uploadDirectory
    ): void {
        // Upload the file first and get the returned filename
        $filename = null;
        if ($photoFile != null) {
            $filename = (new File())->uploadFile($uploadDirectory, $photoFile);
        }

        // Update all the data
        $data = [
            'owner_id' => $neighborId,
            'name' => $name,
            'product_url' => $productUrl,
            'replacement_cost' => $replacementCost,
            'category' => $categoryId,
            'short_name' => $shortName,
            'brand' => $brand,
        ];
        if ($filename) $data['photo_link'] = $filename;

        // Prep (x=y) fields & placeholders
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        // $kvs = array_map(fn($f) => "$f = :$f", $fields);

        $pdo = Util::getDbConnection();

        $pdo->beginTransaction();
        try {
            // Create the new tool
            $sql =
                "insert into tool(" .
                implode(', ', $fields) . ") " .
                "values (" . 
                implode(', ', $placeholders) . ") " .
                "returning id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
            // $toolId = $stmt->fetchColumn();

            // Update the owner's tool count
            $sql =
                "update neighbor set tool_count = tool_count + 1 " .
                "where id = :neighborId";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([ ':neighborId' => $neighborId ]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

}
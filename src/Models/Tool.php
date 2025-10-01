<?php

namespace App\Models;

use Exception;
use \PDO;
use \App\Util;
use Rakit\Validation\Rules\Boolean;
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
                    t.photo_link,
                    'owned' status,
                    t.search_terms
                from
                    tool t
                    inner join tool_category c
                    	on t.category = c.id
                where t.owner_id = ?
        ");

        $stmt->execute(params: [ $neighborId ]);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert search terms string to an array
        foreach($tools as &$t) {
            if ($t['search_terms'] != null) {
                $t['search_terms'] = explode(',', trim($t['search_terms'], '{}'));
                $t['search_terms'] = array_map(function($item) {
                    return trim($item, '"\''); // Removes both single and double quotes
                }, $t['search_terms']);
            }
        }

        return json_encode($tools );
    }

    // Get all the tools available to a neighbor
    // This gets the list of friends and filters based on that
    // searchWithAnd: true means (a & b) while false means (a | b) 
    public function listAllTools(int $neighborId, float $radius_miles = 9999, array $searchTerms = [], bool $searchWithAnd = false): array {
        // Get friends
        $friends = Util::getFriends($neighborId);

        // If no friends, no tools :(
        if (count($friends) == 0) {
            return [];
        }

        // Get all the friend IDs as an array
        $neighborIds = array_column($friends,"friend_id");
        $neighborIdsArrayString = '{' . implode(',', $neighborIds) . '}';

        // Convert search terms into a proper query form
        $includeSearchClause = count($searchTerms) > 0;
        $connector = $searchWithAnd ? "&" : "|";
        $searchString = "and t.search_vector @@ to_tsquery('english', :searchVariable)";
        $searchVariable = implode($connector, $searchTerms);

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
                'available' status,
                t.search_terms,
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
            where
                t.owner_id = any(:friendIds)
                and ST_DWithin(
                    f.home_address_point,
                    me.home_address_point,
                    :radius
                )
                " . ($includeSearchClause ? $searchString : "")
        ;

        // Search parameters
        $params = [
            ':neighborId' => $neighborId,
            ':friendIds' => $neighborIdsArrayString,
            ':radius' => $radius_miles * 1.60934 * 1000
        ];
        // Add search clause if needed
        if ($includeSearchClause) {
            $params[':searchVariable'] = $searchVariable;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Convert search terms string to an array
        foreach($tools as &$t) {
            if ($t['search_terms'] != null) {
                $t['search_terms'] = explode(',', trim($t['search_terms'], '{}'));
                $t['search_terms'] = array_map(function($item) {
                    return trim($item, '"\''); // Removes both single and double quotes
                }, $t['search_terms']);
            }
        }

        return $tools;
    }

    // Get info about a single tool
    public function getTool(int $toolId, int $neighborId): array {
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
                case
                    when t.owner_id = me.id then 'owned'
                    else 'available'
                end status,
                t.search_terms,
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
                    on 1=1
            where t.id = :toolId
        ");

        $stmt->execute(params: [ ':toolId' => $toolId, ':neighborId' => $neighborId ]);
        $tool = $stmt->fetch(PDO::FETCH_ASSOC);

        // Convert search terms string to an array
        if ($tool['search_terms'] != null) {
            $tool['search_terms'] = explode(',', trim($tool['search_terms'], '{}'));
            $tool['search_terms'] = array_map(function($item) {
                return trim($item, '"\''); // Removes both single and double quotes
            }, $tool['search_terms']);
        }
        return $tool;
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
        array $searchTerms,
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
            'name' => $name,
            'product_url' => $productUrl,
            'replacement_cost' => $replacementCost,
            'category' => $categoryId,
            'short_name' => $shortName,
            'brand' => $brand,
            'search_terms' => $searchTerms,
        ];
        if ($filename) $data['photo_link'] = $filename;

        // Prep (x=y) fields & placeholders
        $fields = array_keys($data);
        // $placeholders = array_map(fn($f) => ":$f", $fields);
        $kvs = array_map(fn($f) => "$f = :$f", $fields);

        // Handle the array in $search_terms
        $searchTermArrayString = "{}";
        if (count($searchTerms) > 0) {
            $searchTermArrayString = '{' . implode(',', array_map(fn($v) => '"' . $v . '"', $searchTerms)) . '}';
        }
        $data['search_terms'] = $searchTermArrayString;

        // Add toolid and neighborId to the data
        $data['toolId'] = $toolId;
        $data['neighborId'] = $neighborId;

        // Run an update
        $pdo = Util::getDbConnection();

        $sql =
            "update tool set " . 
            implode(', ', $kvs) . " " .
            "where id = :toolId and owner_id = :neighborId";

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
        array $searchTerms,
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
            'search_terms' => $searchTerms,
        ];
        if ($filename) $data['photo_link'] = $filename;

        // Prep (x=y) fields & placeholders
        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":$f", $fields);
        // $kvs = array_map(fn($f) => "$f = :$f", $fields);

        // Handle the array in $search_terms
        $searchTermArrayString = "{}";
        if (count($searchTerms) > 0) {
            $searchTermArrayString = '{' . implode(',', array_map(fn($v) => '"' . $v . '"', $searchTerms)) . '}';
        }
        $data['search_terms'] = $searchTermArrayString;

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

    // Create a borrow request for a tool.
    public function requestBorrowTool(int $myNeighborId, int $targetToolId, string $message): void {
        $pdo = Util::getDbConnection();

        // Make sure the other guy is my friend as you can only borrow from friends.
        $friends = Util::getFriends($myNeighborId);

        $stmt = $pdo->prepare("
            select owner_id from tool where id = :toolId
        ");
        $stmt->execute(params: [
            ":toolId" => $targetToolId,
        ]);
        $ownerNeighborId = $stmt->fetchColumn();

        // Verify the provided neighbor is my friend
        if (array_key_exists($ownerNeighborId, $friends)) {
            $pdo->beginTransaction();
            try {
                // Create the request in the notification table.  If there's already a request, just do nothing.
                $stmt = $pdo->prepare("
                    insert into notification (to_neighbor, from_neighbor, type, message, data)
                    select t.owner_id, :myNeighborId, 'borrow_request', :message, jsonb_build_object('tool_id', t.id)
                    from tool t
                    where t.id = :toolId
                    and not exists (
                        select 1
                        from notification x
                        where x.to_neighbor = t.owner_id
                        and x.from_neighbor = :myNeighborId
                        and x.type = 'borrow_request'
                        and x.data @> jsonb_build_object('tool_id', t.id)
                    )
                ");
                $stmt->execute(params: [
                    ":myNeighborId" => $myNeighborId,
                    ":toolId" => $targetToolId,
                    ":message" => $message
                ]);
                // If the request was created, send a 2nd notification to the caller
                if ($stmt->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        insert into notification (to_neighbor, from_neighbor, type, message, data)
                        select :myNeighborId, null, 'system_message',
                            'Your request to borrow ' || n.name || '''s ' || t.short_name || ' was sent.  We will send you another message when they reply!',
                            jsonb_build_object('tool_id', t.id)
                        from
                            tool t
                            inner join neighbor n
                                on t.owner_id  = n.id
                        where t.id = :toolId
                    ");
                }
                $stmt->execute(params: [
                    ":myNeighborId" => $myNeighborId,
                    ":toolId" => $targetToolId
                ]);

                $pdo->commit();
            } catch (\PDOException $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            error_log("Permission denied: neighbor $targetToolId is owned by $$ownerNeighborId who is not my friend");
            throw new Exception("Permission denied: neighbor $targetToolId is owned by $$ownerNeighborId who is not my friend");
        }
    }

    // Delete an existing borrow request.
    public function deleteBorrowRequest(int $myNeighborId, int $targetToolId): void {
        $pdo = Util::getDbConnection();

        // Delete the request
        $stmt = $pdo->prepare("
            delete from notification
            where from_neighbor = :myNeighborId
            and type = 'borrow_request'
            and data @> jsonb_build_object('tool_id', :toolId::int)
        ");
        $stmt->execute(params: [
            ":myNeighborId" => $myNeighborId,
            ":toolId" => $targetToolId
        ]);
    }

    // Accept a borrow request.
    public function acceptBorrowRequest(int $myNeighborId, int $targetToolId, int $notificationId, string $message): void {
        // Verify that the tool is mine
        // If not, bail out
        if (! $this->toolBelongsToMe($myNeighborId, $targetToolId)) {
            throw new \Exception("Tool $targetToolId doesn't belong to me, can't loan it!");
        }

        $pdo = Util::getDbConnection();
        $pdo->beginTransaction();
        try {
            // Get the neighbor being used
            $sql = "
                select from_neighbor from notification
                where id = :notificationId
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(params: [
                ":notificationId" => $notificationId,
            ]);
            $otherNeighborId = $stmt->fetchColumn();

            // Create the loan record
            $sql = "
                insert into loan (tool_id, neighbor_id, message, status)
                values (:toolId, :otherNeighborId, :message, 'approved')
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(params: [
                ":toolId" => $targetToolId,
                ":otherNeighborId" => $otherNeighborId,
                ":message" => $message,
            ]);

            // Send notifications
            $sql = "
                insert into notification (to_neighbor, from_neighbor, type, message, data)
                select :otherNeighborId, :myNeighborId, 'borrow_accept', :message, jsonb_build_object('tool_id', :toolId::int)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(params: [
                ":otherNeighborId" => $otherNeighborId,
                ":myNeighborId" => $myNeighborId,
                ":message" => $message,
                ":toolId" => $targetToolId,
            ]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // Reject a borrow request.
    public function rejectBorrowRequest(int $myNeighborId, int $targetToolId, int $notificationId): void {
        
    }






    /////
    // Helper functions
    /////


    // Does a particular tool belong to me?
    private function toolBelongsToMe(int $myNeighborId, int $toolId): bool {
        $pdo = Util::getDbConnection();
        $stmt = $pdo->prepare("
            select count(*)
            from tool
            where owner_id = :myNeighborId
            and id = :toolId
        ");
        $stmt->execute(params: [
            ":myNeighborId" => $myNeighborId,
            ":toolId" => $toolId,
        ]);

        return $stmt->fetchColumn() > 0;
    }
}
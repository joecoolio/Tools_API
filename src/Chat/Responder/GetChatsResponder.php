<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class GetChatsResponder extends Responder {
    public function respond(TcpConnection $connection, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($connection);

        $pdo = Util::getDbConnection();

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.started_ts,
                array_agg(neighbor_id) FILTER (WHERE neighbor_id != :me) AS other_members
            from
                chat c
                inner join chat_neighbor cn
                    on c.id = cn.chat_id
            GROUP BY c.id
            HAVING bool_or(neighbor_id = :me)
            order by c.started_ts
        ");
        $stmt->execute(params: [
            ":me" => $myNeighborId,
        ]);
        $chats = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        // Convert other_members to an array of numbers
        foreach($chats as &$c) {
            if ($c['other_members'] != null) {
                $c['other_members'] = explode(',', trim($c['other_members'], '{}'));
                $c['other_members'] = array_map(function($item): int { return (int) $item; }, $c['other_members']);
            }
        }


        return [
            "type" => "get_chats_result",
            "chats" => $chats,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class GetChatsResponder extends Responder {
    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $dbConnPool->prepare("
            SELECT
                c.id,
                c.started_ts,
                max(cm.send_ts) latest_message_ts,
                array_agg(distinct neighbor_id) FILTER (WHERE neighbor_id != :me) AS other_members,
                bool_and(:me = any(cm.read_by) or :me = from_neighbor) read
            from
                chat c
                inner join chat_neighbor cn
                    on c.id = cn.chat_id
                inner join chat_message cm
                    on c.id = cm.chat_id
            GROUP BY c.id
            HAVING bool_or(neighbor_id = :me)
            order by c.started_ts
        ");
        $result = $stmt->execute(params: [
            "me" => $myNeighborId,
        ]);
        $chats = iterator_to_array($result, false);

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

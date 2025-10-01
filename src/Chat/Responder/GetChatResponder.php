<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class GetChatResponder extends Responder {
    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);

        // Fields for the new message
        $chat_id = $request['chat_id'];
        
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
            where
                c.id = :chat_id
            group by
                c.id
            HAVING bool_or(neighbor_id = :me)
        ");
        $result = $stmt->execute(params: [
            "me" => $myNeighborId,
            "chat_id" => $chat_id,
        ]);
        foreach ($result as $chat) {
            return [
                "type" => "get_chat_result",
                "chat" => $chat,
                "result" => true
            ];
        }

        return [
            "type" => "get_chat_result",
            "chat_id" => $chat_id,
            "result" => false
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

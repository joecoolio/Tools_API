<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class NewMessageSender extends Responder {

    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);
        // Fields for the new message
        $msg_id = $request['msg_id'];

        try {
        // Get all the messages for the chat
        $stmt = $dbConnPool->prepare("
            select id, chat_id, from_neighbor, send_ts, message,
                read_by @> ARRAY[:me]::int[] read
            from chat_message
            where id = :msg_id
        ");
        $result = $stmt->execute(params: [
            "msg_id" => $msg_id,
            "me" => $myNeighborId,
        ]);
        foreach ($result as $msg) {
            // Flag which messages are from me and which is from others
            $msg['sent_by_me'] = $msg['from_neighbor'] == $myNeighborId;

            return [
                "type" => "new_message_result",
                "chat_id" => $msg['chat_id'],
                "message" => $msg,
                "result" => true
            ];
        }
    } catch (\Exception $e) {
    }

        // If something goes wrong, return failure
        return [
            "type" => "new_message_result",
            "message_id" => $msg_id,
            "result" => false
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class GetMessagesInChatResponder extends Responder {

    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);

        // Fields for the new message
        $chat_id = $request['chat_id'];

        // Get all the messages for the chat
        $stmt = $dbConnPool->prepare("
            select id, from_neighbor, send_ts, message,
                :me = any(read_by) or :me = from_neighbor read
            from chat_message
            where chat_id = :chat_id
            order by send_ts desc
        ");
        $result = $stmt->execute(params: [
            "chat_id" => $chat_id,
            "me" => $myNeighborId,
        ]);
        $messages = iterator_to_array($result, false);

        // Flag which messages are from me and which is from others
        foreach($messages as &$msg) {
            $msg['sent_by_me'] = $msg['from_neighbor'] == $myNeighborId;
        }

        return [
            "type" => "get_messages_result",
            "chat_id" => $chat_id,
            "messages" => $messages,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class GetMessagesInChatResponder extends Responder {

    public function respond(TcpConnection $connection, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($connection);

        // Fields for the new message
        $chat_id = $request['chat_id'];

        $pdo = Util::getDbConnection();

        // Get all the messages for the chat
        $stmt = $pdo->prepare("
            select id, from_neighbor, send_ts, message,
                read_by @> ARRAY[:me]::int[] read
            from chat_message
            where chat_id = :chat_id
            order by send_ts desc
        ");
        $stmt->execute(params: [
            ":chat_id" => $chat_id,
            ":me" => $myNeighborId,
        ]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Flag which messages are from me and which is from others
        $myNeighborId = $this->getMyNeighborId($connection);
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

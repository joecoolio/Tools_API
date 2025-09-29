<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class NewMessageSender extends Responder {

    public function respond(TcpConnection $connection, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($connection);

        // Fields for the new message
        $msg_id = $request['msg_id'];

        $pdo = Util::getDbConnection();

        // Get all the messages for the chat
        $stmt = $pdo->prepare(query: "
            select id, chat_id, from_neighbor, send_ts, message,
                read_by @> ARRAY[:me]::int[] read
            from chat_message
            where id = :msg_id
        ");
        $stmt->execute(params: [
            ":msg_id" => $msg_id,
            ":me" => $myNeighborId,
        ]);
        $msg = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Flag which messages are from me and which is from others
        $msg['sent_by_me'] = $msg['from_neighbor'] == $myNeighborId;

        return [
            "type" => "new_message_result",
            "chat_id" => $msg['chat_id'],
            "message" => $msg,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

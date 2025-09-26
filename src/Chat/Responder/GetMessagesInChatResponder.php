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

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $pdo->prepare("
            select id, from_neighbor, send_ts, message
            from chat_message
            where chat_id = :chat_id
            order by send_ts desc
        ");
        $stmt->execute(params: [
            ":chat_id" => $chat_id
        ]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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

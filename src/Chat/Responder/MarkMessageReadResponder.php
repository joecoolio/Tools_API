<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class MarkMessageReadResponder extends Responder {
    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);

        // Fields for the new message
        $messageId = $request['id'];

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $dbConnPool->prepare("
            UPDATE chat_message
            SET read_by = array_append(read_by, :me)
            WHERE id = :messageId
            AND NOT read_by @> ARRAY[:me]::int[]
        ");
        $stmt->execute(params: [
            "me" => $myNeighborId,
            "messageId" => $messageId,
        ]);
        $stmt->execute();

        return [
            "type" => "mark_message_read_result",
            "id" => $messageId,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class MarkMessageReadResponder extends Responder {
    public function respond(TcpConnection $connection, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($connection);

        // Fields for the new message
        $messageId = $request['id'];

        $pdo = Util::getDbConnection();

        // Create the request in the notification table.  If there's already a request, just do nothing.
        $stmt = $pdo->prepare("
            UPDATE chat_message
            SET read_by = array_append(read_by, :me)
            WHERE id = :messageId
            AND NOT read_by @> ARRAY[:me]::int[]
        ");
        $stmt->bindValue(':me', $myNeighborId, \PDO::PARAM_INT);
        $stmt->bindValue(':messageId', $messageId);
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

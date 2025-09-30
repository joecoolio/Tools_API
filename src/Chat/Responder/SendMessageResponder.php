<?php
namespace App\Chat\Responder;

use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class SendMessageResponder extends Responder {

    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($client);

        // Fields for the new message
        $toNeighborId = $request['to'];
        $message = $request['message'];

        $transaction = $dbConnPool->beginTransaction();
        try {
            // Check to see if a chat already exists between the sender & receiver
            $stmt = $dbConnPool->prepare("
                SELECT chat_id
                FROM chat_neighbor cn
                GROUP BY chat_id
                HAVING COUNT(*) = 2
                AND bool_and(neighbor_id IN (:me, :them))
            ");
            $result = $stmt->execute(params: [
                "me" => $myNeighborId,
                "them" => $toNeighborId,
            ]);
            $chatId = null;
            foreach ($result as $row) {
                $chatId = $row['chat_id'];
            }

            // If it doesn't, create a new chat
            if ($chatId == null) {
                // Create the chat
                $stmt = $dbConnPool->prepare("
                    insert into chat (started_by)
                    values (:me)
                    returning id
                ");
                $result = $stmt->execute(params: [
                    "me" => $myNeighborId,
                ]);
                foreach ($result as $row) {
                    $chatId = $row['id'];
                }

                // Add both people to the chat
                $stmt = $dbConnPool->prepare("
                    insert into chat_neighbor (chat_id, neighbor_id)
                    values (:chat_id, :neighbor)
                ");
                $stmt->execute(params: [
                    "chat_id" => $chatId,
                    "neighbor" => $myNeighborId,
                ]);
                $stmt->execute(params: [
                    "chat_id" => $chatId,
                    "neighbor" => $toNeighborId,
                ]);
            }
            
            // Then create the message
            $stmt = $dbConnPool->prepare("
                insert into chat_message (chat_id, from_neighbor, message)
                values (:chat_id, :from, :message)
                returning id
            ");
            $result = $stmt->execute(params: [
                "chat_id" => $chatId,
                "from" => $myNeighborId,
                "message" => $message
            ]);
            $messageId = null;
            foreach ($result as $row) {
                $messageId = $row['id'];
            }

            $transaction->commit();
        } catch (\PDOException $e) {
            $transaction->rollBack();
            throw $e;
        }

        // echo "Sent message from $myNeighborId to $toNeighborId id $id\n";

        return [
            "type" => "send_message_result",
            "chat_id" => $chatId,
            "message_id" => $messageId,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

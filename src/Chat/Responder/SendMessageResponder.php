<?php
namespace App\Chat\Responder;

use App\Util;
use Workerman\Connection\TcpConnection;

class SendMessageResponder extends Responder {

    public function respond(TcpConnection $connection, array $request): array {
        // Get the user ID
        $myNeighborId = Responder::getMyNeighborId($connection);

        // Fields for the new message
        $id = $request['id'];
        $toNeighborId = $request['to'];
        $message = $request['message'];

        $pdo = Util::getDbConnection();

        $pdo->beginTransaction();
        try {
            // Check to see if a chat already exists between the sender & receiver
            $stmt = $pdo->prepare("
                SELECT chat_id
                FROM chat_neighbor cn
                GROUP BY chat_id
                HAVING COUNT(*) = 2
                AND bool_and(neighbor_id IN (:me, :them))
            ");
            $stmt->execute(params: [
                ":me" => $myNeighborId,
                ":them" => $toNeighborId,
            ]);
            $chatId = $stmt->fetchColumn();

            // If it doesn't, create a new chat
            if ($chatId == null) {
                // Create the chat
                $stmt = $pdo->prepare("
                    insert into chat (started_by)
                    values (:me)
                    returning id
                ");
                $stmt->execute(params: [
                    ":me" => $myNeighborId,
                ]);
                $chatId = $stmt->fetchColumn();

                // Add both people to the chat
                $stmt = $pdo->prepare("
                    insert into chat_neighbor (chat_id, neighbor_id)
                    values (:chat_id, :neighbor)
                ");
                $stmt->execute(params: [
                    ":chat_id" => $chatId,
                    ":neighbor" => $myNeighborId,
                ]);
                $stmt->execute(params: [
                    ":chat_id" => $chatId,
                    ":neighbor" => $toNeighborId,
                ]);
            }
            
            // Then create the message
            $stmt = $pdo->prepare("
                insert into chat_message (id, chat_id, from_neighbor, message)
                values (:id, :chat_id, :from, :message)
            ");
            $stmt->execute(params: [
                ":id" => $id,
                ":chat_id" => $chatId,
                ":from" => $myNeighborId,
                ":message" => $message
            ]);

            $pdo->commit();
        } catch (\PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }

        // echo "Sent message from $myNeighborId to $toNeighborId id $id\n";

        return [
            "type" => "send_message_result",
            "chat_id" => $chatId,
            "message_id" => $id,
            "result" => true
        ];
    }

    // ID is required
    public function identificationRequired(): bool {
        return true;
    }
}

<?php

namespace App\Chat\Responder;
use Amp\Websocket\WebsocketClient;
use Amp\Postgres\PostgresConnectionPool;

class PingResponder extends Responder {
    public function respond(WebsocketClient $client, PostgresConnectionPool $dbConnPool, array $request): array {
        $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        $timestamp = $dt->format('Y-m-d\TH:i:s.v\Z');

        return [
            "type" => "pong",
            "timestamp" => $timestamp
        ];
    }
}

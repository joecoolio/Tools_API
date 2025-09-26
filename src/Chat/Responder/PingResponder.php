<?php

namespace App\Chat\Responder;
use Workerman\Connection\TcpConnection;

class PingResponder extends Responder {
    public function respond(TcpConnection $connection, array $request): array {
        $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        $timestamp = $dt->format('Y-m-d\TH:i:s.v\Z');

        return [
            "type" => "pong",
            "timestamp" => $timestamp
        ];
    }
}

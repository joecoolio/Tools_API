<?php

namespace App\Models;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class PushNotification extends BaseModel {
    // Send web push
    public function sendWebPush(): void {
// error_log(\Minishlink\WebPush\VAPID::createVapidKeys());

// $ch = curl_init();
// curl_setopt($ch, CURLOPT_URL, "https://php.watch");
// curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA); // Use native CA store
// $response = curl_exec($ch);
// curl_close($ch);
// echo $response;

        $auth = [
            'VAPID' => [
                'subject' => $_ENV['WEBPUSH_SUBJECT'], // can be a mailto: or your website address
                'publicKey' => $_ENV['WEBPUSH_PUBLICKEY'],
                'privateKey' => $_ENV['WEBPUSH_PRIVATEKEY']
            ],
        ];

        // store the client-side `PushSubscription` object (calling `.toJSON` on it) as-is and then create a WebPush\Subscription from it
        $subscription = Subscription::create(
           json_decode(
            '{"endpoint":"https://updates.push.services.mozilla.com/wpush/v2/gAAAAABouDxc0_nK9OK91JKN5Gs_Wjce-4e3V8MdMqOhIihtu99B1rp8OR6i4LQRdnL2EIMSy5QMVg-5RC2BP50q3Bz2rRuBgvOsoFd7lrGlrn7i67EjzaYqhmcYrDMcKEjIWT_CuElc2VMa70hRoosA5LtikYXN28X6Xf2JnBwRsMMrd5QJ95o","expirationTime":null,"keys":{"auth":"0Um15VpYhYthTFvbxtcuCg","p256dh":"BOkeHRr9XyMMqnw2bR--pSGeYKvZcPmkIFNrADSyBbfI2Yi_hiugAtwmFqE0X9iQ5t47tZuqJaIh_Dg3V_tWv6A"}}'
            , associative:true)
        );

        // array of notifications
        $notifications = [
            [
                'subscription' => $subscription,
                'payload' => '{"message":"Hello World!"}',
            ]
        ];

        $webPush = new WebPush($auth);

        $report = $webPush->sendOneNotification(
            $notifications[0]['subscription'],
            $notifications[0]['payload'], // optional (defaults null)
        );
        var_dump( $report );
    }

}
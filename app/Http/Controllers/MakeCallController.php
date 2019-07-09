<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PAMI\Client\Exception\ClientException;
use PAMI\Client\Impl\ClientImpl;
use PAMI\Message\Action\OriginateAction;

class MakeCallController extends Controller
{
    public function outgoingCall(Request $request)
    {
        $event = $request->event;
        $secret = $request->secret;
        $from = $request->from;
        $to = $request->to;
        $context = $request->context;

        $options = [
            'host' => '10.0.0.54',
            'scheme' => 'tcp://',
            'port' => 5038,
            'username' => 'vtiger',
            'secret' => 'vtiger123',
            'connect_timeout' => 10000,
            'read_timeout' => 10000
        ];

        $client = new ClientImpl($options);

        Log::info($from);
        $to = starts_with($to, "0") ? $to : "0" . $to;


        $action = new OriginateAction("SIP/$to@TCL");
        $action->setTimeout(30000);
        $action->setExtension($from);
        $action->setContext("default");
        $action->setPriority(1);
        $action->setCallerId($to);

        try {
            $client->open();
            $response = $client->send($action);
            $client->close();
        } catch (ClientException $e) {
            Log::error($e->getMessage());
            return $e->getMessage();
        }

        Log::info($response->getMessage());
        return $response->getMessage() == "Originate successfully queued" ? "Success" : "Error";
    }
}

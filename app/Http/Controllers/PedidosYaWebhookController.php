<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PedidosYaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        info("WEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n");

        info(print_r($request->all(),true)."\n");

        info("Info recibida: \n\n ".$request->__toString());

        $vendorId = explode('order/',$request->path())[1];

        $jwt = $request->header('Authorization');

        $token = trim(explode('Bearer ',$jwt)[1]);

        $payload = json_decode(base64_decode(explode('.',$token)[1]));

        info(print_r((array)$payload,true)."\n");

        return response("",200);

    }
}

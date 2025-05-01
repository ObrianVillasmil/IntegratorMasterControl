<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PedidosYaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        info("WEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n");

        info($request->header('Authorization')."\n");

        info($request->url()."\n");

        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);

    }
}

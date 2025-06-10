<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RappiWebhookcontroller extends Controller
{
    public function getNotification(Request $request)
    {
        info('getNotification RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class RappiWebhookcontroller extends Controller
{
    const EVENTS = [
        'NEW_ORDER',
        'ORDER_EVENT_CANCEL',
        'ORDER_OTHER_EVENT',
        'ORDER_RT_TRACKING',
        'MENU_APPROVED',
        'MENU_REJECTED',
        'PING',
        'STORE_CONNECTIVITY'
    ];

    public function getNotification(Request $request)
    {
        info('getNotification RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }
}

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

    public function newOrder(Request $request)
    {
        info('newOrder RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function orderEventCancel(Request $request)
    {
        info('orderEventCancel RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function orderOtherEvent(Request $request)
    {
        info('orderOtherEvent RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function orderRtTracking(Request $request)
    {
        info('orderRtTracking RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function menuApproved(Request $request)
    {
        info('menuApproved RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function menuRejected(Request $request)
    {
        info('menuRejected RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function pingRappi(Request $request)
    {
        info('pingRappi RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

    public function storeConnectvity(Request $request)
    {
        info('storeConnectvity RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        return response("",200);
    }

}

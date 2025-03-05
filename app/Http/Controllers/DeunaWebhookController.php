<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DeunaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        info('integracion-deuna: '. $request->__toString());

    }
}

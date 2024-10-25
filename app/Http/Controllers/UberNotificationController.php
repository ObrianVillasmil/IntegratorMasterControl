<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UberNotificationController extends Controller
{
    public function getNotification(Request $request)
    {
        $signatureUber =  $request->headers->get('X-Uber-Signature');
        info($signatureUber);
        info($request->all());
        return response('',200);

    }
}

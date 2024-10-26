<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UberNotificationController extends Controller
{
    public function getNotification(Request $request)
    {
        $signatureUber =  $request->headers->get('X-Uber-Signature');

        if(isset($signatureUber) && $signatureUber != ''){


            info($signatureUber);
            info($request->all());


           $hmac =  hash_hmac('sha256',json_encode($request->all()),'B_hWdS2sPQzZeckJHE06v1ryWHnDE3ByF0fN0D4A');

           info($hmac);

            return response('',200);

        }else{

            return response('',403);

        }


    }
}

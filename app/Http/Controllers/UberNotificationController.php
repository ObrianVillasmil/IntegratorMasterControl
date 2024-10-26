<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UberNotificationController extends Controller
{
    public function getNotification(Request $request)
    {
        $signature =  $request->headers->get('X-Uber-Signature');

        if(isset($signature) && $signature != ''){


            //info($request->all());
            info($request->toString());
            //$json = json_encode($request->all());
            //info($json);
            $hmac = hash_hmac('sha256',$request->__toString(),'B_hWdS2sPQzZeckJHE06v1ryWHnDE3ByF0fN0D4A');

            info($signature);
            info($hmac);
            info('comparacion: '.hash_equals($signature,$hmac));

            return response('',200);

        }else{

            return response('',403);

        }


    }
}

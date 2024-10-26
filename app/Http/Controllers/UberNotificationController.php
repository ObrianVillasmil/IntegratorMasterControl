<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class UberNotificationController extends Controller
{
    public function getNotification(Request $request)
    {
        $signatureUber =  $request->headers->get('X-Uber-Signature');
        info($signatureUber);
        if(isset($signatureUber) && $signatureUber != ''){


            info($request->all());
            info($request->getContent());
            //$json = json_encode($request->all());
            //info($json);
            $hmac = hash_hmac('sha256',$request->getContent(),'B_hWdS2sPQzZeckJHE06v1ryWHnDE3ByF0fN0D4A');

            info($signatureUber);
            info($hmac);
            info('comparacion: '.hash_equals($signatureUber,$hmac));

            return response('',200);

        }else{

            return response('',403);

        }


    }
}

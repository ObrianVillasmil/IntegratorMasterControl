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
            info($request->getContent());
            //$json = json_encode($request->all());
            //info($json);
            $hmac = hash_hmac('sha256',$request->getContent(),'B_hWdS2sPQzZeckJHE06v1ryWHnDE3ByF0fN0D4A');

            info('X-Uber-Signature: '.$signature);

            info('webhook body:'.$request->getContent());

            info('hmac sha256:'.$hmac);
           // info('comparation: '.hash_equals($signature,$hmac));

            return response('',200);

        }else{

            return response('',403);

        }


    }
}

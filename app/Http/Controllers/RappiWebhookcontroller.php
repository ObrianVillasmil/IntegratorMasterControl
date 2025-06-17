<?php

namespace App\Http\Controllers;

use App\Models\SecretWebHookRappi;
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
        info("\n newOrder RAPPI");
        info("Info recibida: \n\n ".$request->__toString());

        $secret = SecretWebHookRappi::where('event','NEW_ORDER')->first();

        $request->query->add([
            'secret'=> $secret->secret,
            'event' => 'NEW_ORDER'
        ]);

        $validSign = self::validateSignature($request);

        if(!$validSign['success']){

            info("\n Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized \n {$validSign['msg']}",401);

        }




        return response(self::validateSignature($request),200);


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

    private static function validateSignature(Request $request)
    {
        try {

            if(!$request->secret)
                throw new \Exception("No se ha configurado el secret del evento {$request->event}");

            $signature = $request->header('Rappi-Signature');

            if(!$signature)
                throw new \Exception('No se ha recibido la firma de la petición');

            $arrSignature = explode(',', $signature);

            if(count($arrSignature) != 2)
                throw new \Exception('El formato de la firma en la petición no es válida');

            $t = null;

            foreach ($arrSignature as $x => $signature) {

                $arr = explode('=', $signature);

                if(count($arr) != 2)
                    throw new \Exception('El formato de la firma en la petición no es válida');

                if($x == 0 && $arr[0] != 't'){

                    throw new \Exception('El formato de la firma en la petición no es válida');

                }else if($x == 0){

                    $t = $arr[1];
                }

                if($x == 1 && $arr[0] != 'sign'){

                    throw new \Exception('El formato de la firma en la petición no es válida');

                }else if($x == 1 ){

                    $sign = $arr[1];

                }

            }

            $signedPayload = "{$t}.{$request->getContent()}";

            $success = hash_hmac('sha256', $signedPayload, $request->secret) === $sign;

            if(!$success){

                info("\n Verificacion de firma Rappi ");
                info("signedPayload \n {$signedPayload}\n");
                info("request->secret \n {$request->secret}\n");
                info("sign \n {$sign}\n");
                info("hmac: \n ".hash_hmac('sha256', $signedPayload, $request->secret)."\n");

            }

            return ['success' => $success ];

        } catch (\Exception $e) {

            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];



        }

    }

}

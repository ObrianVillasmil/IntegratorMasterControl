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

        $request->query->add(['secret'=>'7DC32C2162CE1250720884596DF87B7C988F38580E91167278B983E15B7A2D29']);

        return self::validateSignature($request);//response(self::validateSignature($request),200);
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

            return [
                'success' => hash_hmac('sha256', $signedPayload, $request->secret) === $sign
            ];

        } catch (\Exception $e) {

            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];



        }

    }

}

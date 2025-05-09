<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebHookUber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UberWebhookController extends Controller
{
    const ORDER_NOTIFICATION_TYPES = [
        'orders.notification',
        'delivery.state_changed',
        'orders.release',
        'orders.failed',
        'orders.fulfillment_issues.resolved',
        'order.fulfillment_issues.resolved'
    ];

    const ORDER_NOTIFICATION_FAILURE = ['orders.failure'];

    public function getNotification(Request $request) : Response
    {
        try {

            $signature = $request->headers->get('X-Uber-Signature');

            if(isset($signature) && $signature != ''){

                info('integracion-uber: '. $request->__toString());

                $content = $request->getContent();
                $data = json_decode($content);

                if(isset($data->meta->user_id)){

                    $storeId = $data->meta->user_id;

                }else{

                    if(!isset($data->meta->order_id))
                        return response('',403);

                    $whkNotifcation = json_decode(WebHookUber::where('data->meta->resource_id',$data->meta->order_id)->first()->data);

                    $storeId = $whkNotifcation->meta->user_id;

                }

                if(!isset($storeId))
                    throw new \Exception('No se ha encontrado el storeId en la bd para la petición '.$request->__toString());

                $company = Company::where('token',$storeId)->first();

                $data->connect = $company->connect;

                $hmac = hash_hmac('sha256',$content,$company->signing_key_webhook_uber);

                if(hash_equals($signature,$hmac)){

                    $whu= WebHookUber::create(['data' => json_encode($data)]);

                    $data->webook_uber_id = $whu->id;
                    $data->store_id = $storeId;

                    if(in_array($data->event_type,self::ORDER_NOTIFICATION_TYPES)){

                        UberNotificationController::orderNotification($data);

                    }else if(in_array($data->event_type,self::ORDER_NOTIFICATION_FAILURE)){

                        UberNotificationController::orderNotificationFailure($data);

                    }else{

                        info("El event_type {$data->event_type} no esta en la lista de event_types");

                    }

                }else{

                    info('El hash no coincide en la peticion a /integracion-uber: '. $request->__toString());
                    return response('',403);

                }

            }else{

                info('No existe el header en la peticion desconcida a /integracion-uber: '. $request->__toString());
                return response('',403);

            }

            return response('',200);

        } catch (\Exception $e) {

            info($e->getMessage());
            return response('',200);


        }

    }

}

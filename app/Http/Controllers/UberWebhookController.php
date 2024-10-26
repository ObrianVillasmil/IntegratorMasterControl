<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebHookUber;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UberWebhookController extends Controller
{
    public function getNotification(Request $request) : Response
    {

        try {

            $signature = $request->headers->get('X-Uber-Signature');

            if(isset($signature) && $signature != ''){

                $content = $request->getContent();
                $data = json_decode($content);

                //info('webhook body:'.$content);
                //info('$data->meta->user_id: '.$data->meta->user_id);

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

                $request->query->add(['connect' => $company->connect]);

                $hmac = hash_hmac('sha256',$content,$company->signing_key_webhook_uber);

                //info('X-Uber-Signature: '.$signature);
                //info('hmac sha256: '.$hmac);
                //info('comparation: '.hash_equals($signature,$hmac));

                if(hash_equals($signature,$hmac)){

                    WebHookUber::create(['data' => json_encode($data)]);

                    if($request->event_type === 'orders.notification'){

                        UberNotificationController::orderNotification($data);

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

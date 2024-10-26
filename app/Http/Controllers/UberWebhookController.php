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

                info('webhook body:'.$content);
                info('$request->meta->user_id '.isset($request->meta->user_id));
                if(isset($request->meta->user_id)){

                    $storeId = $request->meta->user_id;

                }else{

                    if(!isset($request->data->meta->order_id))
                        return response('',403);

                    $whkNotifcation = json_decode(WebHookUber::where('data->meta->resource_id',$request->data->meta->order_id)->first()->data);

                    $storeId = $whkNotifcation->data->meta->user_id;

                }

                $company = Company::where('token',$storeId)->first();

                $request->query->add(['connect' => $company->connect]);

                $hmac = hash_hmac('sha256',$content,$company->signing_key_webhook_uber);

                info('X-Uber-Signature: '.$signature);
                info('hmac sha256: '.$hmac);
                info('comparation: '.hash_equals($signature,$hmac));

                if(hash_equals($signature,$hmac)){

                    WebHookUber::create(['data' => $request->json()]);

                    if($request->event_type === 'orders.notification'){

                        UberNotificationController::orderNotification($request);

                    }

                }else{

                    info('El hash no coincide en la peticion a /integracion-uber: '. $request->__toString());
                    return response('',403);

                }

                return response('',200);

            }else{

                info('No existe el header en la peticion desconcida a /integracion-uber: '. $request->__toString());
                return response('',403);

            }

        } catch (\Exception $e) {

            info($e->getMessage());

        }

    }

}

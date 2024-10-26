<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebhookUber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UberNotificationController extends Controller
{
    public static function orderNotification(Object $data) : Array
    {
        info('connection: '.$data->connect);

        $store = DB::connection($data->connect)->table('sucursal_tienda_uber')->where('store_id',$data->meta->user_id)->first();

        $client = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '. $store->token,
            'Accept: application/json'
        ];

        $params = http_build_query(['expand' =>'carts,deliveries,payment']);

        curl_setopt($client, CURLOPT_URL, $data->resource_href.'?'.$params);
        curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($client, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 15);

        $response = curl_exec($client);

        $codigoHttp = curl_getinfo($client, CURLINFO_HTTP_CODE);

        curl_close($client);

        WebhookUber::where('id',$data->webook_uber_id)->update(['order' => $response]);

        $response = json_decode($response);

        if($codigoHttp == 200){


        }else{



        }

        return [];
    }
}

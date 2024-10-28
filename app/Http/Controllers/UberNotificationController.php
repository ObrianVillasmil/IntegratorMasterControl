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
        try {

            $store = DB::connection($data->connect)->table('sucursal_tienda_uber')->where('store_id',$data->store_id)->first();

            $client = curl_init();

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer '. $store->token,
                'Accept: application/json'
            ];

            $params = http_build_query(['expand' => 'carts,deliveries,payment']);

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

            info('$codigoHttp '.$codigoHttp);
            info('$data->event_type: ' .$data->event_type);

            if(isset($response->order)){

                info('$response->order->state '.$response->order->state);

                if($codigoHttp >= 200 && $codigoHttp <= 299){

                    //CREA LA PRECUENTA EN EL MASTERPOS CORRESPONDIENTE
                    if($data->event_type == 'orders.notification' && $response->order->state === 'OFFERED'){

                        $customerIdentification = null;
                        $customerEmail = null;
                        $customer = null;
                        $customerAddress = null;
                        $customerPhone = null;
                        $items = [];

                        if(isset($response->order->customers->tax_profiles)){

                            $customerIdentification = $response->order->customers->tax_profiles[0]->tax_id;
                            $customerIdentification = $response->order->customers->tax_profiles[0]->email;
                            $customer = $response->order->customers->tax_profiles[0]->legal_entity_name;
                            $customerAddress = $response->order->customers->tax_profiles[0]->billing_address;

                        }

                        if(isset($response->order->contact->phone))
                            $customerPhone = $response->order->contact->phone->number;

                        if(isset($response->order->carts) && isset($response->order->carts[0]->items)){

                            foreach ($response->order->carts[0]->items as $item) {

                                $dataItem = explode('-',$item->external_data);
                                $commnet = '';

                                if(isset($item->customer_request->special_instructions))
                                    $commnet = $item->customer_request->special_instructions;

                                $items[] = [
                                    'type' => $dataItem[0],
                                    'id' => $dataItem[1],
                                    'name' => $item->title,
                                    'tax' => $dataItem[5],
                                    'quantity' => $item->quantity->amount,
                                    'ingredient' => 0,
                                    'comment' => $commnet,
                                    'sub_total_price' => $dataItem[7]/100,
                                    'id_pcpp' => null,

                                ];

                                if(isset($item->selected_modifier_groups)){

                                    foreach ($item->selected_modifier_groups as $question) {

                                        if(isset($question->selected_items)){

                                            foreach ($question->selected_items as $res) {

                                                $dataResponse = explode('-',$res->external_data);

                                                $items[] = [
                                                    'type' => $dataResponse[0],
                                                    'id' => $dataResponse[1],
                                                    'name' => $res->title,
                                                    'ingredient' => 1,
                                                    'tax' => $dataResponse[7],
                                                    'quantity' => $res->quantity->amount,
                                                    'id_pcpp' => $dataResponse[3],
                                                    'sub_total_price' => $dataResponse[9]/100,
                                                    'comment' => '',
                                                ];

                                            }

                                        }

                                    }

                                }

                            }

                        }

                        info('items');
                        info((string)json_encode($items));

                        $resAccount = MpFunctionController::createMpAccount(new Request([
                            'id_branch_office' => $store->id_sucursal,
                            'order_id' => $response->order->id,
                            'connect' => base64_encode($data->connect),
                            'name' => $response->order->ordering_platform.' '.$response->order->display_id,
                            'ordering_platform' => $response->order->ordering_platform,
                            'customer' => $customer,
                            'customer_identifcation' => $customerIdentification,
                            'customer_address' => $customerAddress,
                            'customer_email' => $customerEmail,
                            'customer_phone' => $customerPhone,
                            'total' => $response->order->payment->payment_detail->order_total->gross->amount_e5/100000,
                            'payment_type_id' => 4, //VINCULAR UN TIPO DE PAGO EN LA CONFIGRURACION DE LA TIENDA
                            'items' => (string)json_encode($items)
                        ]));

                        info('resAccount:');
                        info((array)$resAccount);

                    }else if($data->event_type == 'delivery.state_changed'){



                    }

                }else{

                    //NO SE RECIBIÓ EL ORDER EN LA PETICION
                    info('No se recibió el order en la petición '.($data->resource_href.'?'.$params));

                }

            }

        } catch (\Exception $e) {

            info('Error orderNotification: '.$e->getMessage().' '.$e->getLine().' '.$e->getFile());

        }

        return [];
    }
}

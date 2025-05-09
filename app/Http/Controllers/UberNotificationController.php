<?php

namespace App\Http\Controllers;

use App\Models\WebhookUber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UberNotificationController extends Controller
{
    public static function orderNotification(Object $data)
    {
        try {

            $store = DB::connection($data->connect)->table('sucursal_tienda_uber as stu')
            ->join('sucursal as s','s.id_sucursal','stu.id_sucursal')
            ->where('stu.store_id',$data->store_id)->first();

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
                    if(in_array($data->event_type,['orders.notification','orders.fulfillment_issues.resolved'])){

                        if($data->event_type === 'orders.fulfillment_issues.resolved'){

                            $deleteOrder = MpFunctionController::deleteMpOrderAppDelivery(new Request([
                                'order_id' => $response->order->id,
                                'connect' => base64_encode($data->connect),
                            ]));

                            $deleteOrder = $deleteOrder->getData(true);

                            if(!$deleteOrder['success']){

                                info('Error orderNotification UBER: ');
                                info($deleteOrder['msg']);

                            }

                        }

                        $customerIdentification = null;
                        $customerEmail = 'a@gmail.com';
                        $customer = null;
                        $customerAddress = null;
                        $customerPhone = null;
                        $items = [];

                        if(isset($response->order->customers[0]) && isset($response->order->customers[0]->tax_profiles)){

                            $customerIdentification = $response->order->customers[0]->tax_profiles[0]->tax_id;
                            $customerEmail = $response->order->customers[0]->tax_profiles[0]->email;
                            $customer = $response->order->customers[0]->tax_profiles[0]->legal_entity_name;
                            $customerAddress = $response->order->customers[0]->tax_profiles[0]->billing_address;

                        }

                        if(isset($response->order->customers[0]->contact->phone))
                            $customerPhone = $response->order->customers[0]->contact->phone->number;

                        if(isset($response->order->carts) && isset($response->order->carts[0]->items)){

                            foreach ($response->order->carts[0]->items as $item) {

                                $dataItem = explode('-',$item->external_data);
                                $commnet = '';
                                $discount = 0;
                                $subTotal = $dataItem[7]/100;
                                $jsonDiscount= null;

                                if(isset($item->customer_request->special_instructions))
                                    $commnet = $item->customer_request->special_instructions;

                                //EXISTEN PROMOCIONES EN EL ITEM
                                if(isset($response->order->payment->tax_reporting->breakdown->promotions)){

                                    $arrItemsPromo = array_filter($response->order->payment->tax_reporting->breakdown->promotions, function($itemPromo) use ($item){
                                        return $item->cart_item_id === $itemPromo->instance_id && $itemPromo->description === 'ITEM_PROMOTION';
                                    });

                                    foreach($arrItemsPromo as $itemPromo){

                                        $discount = number_format(($itemPromo->net_amount->amount_e5/100000)*-1,2,'.','');

                                        $jsonDiscount = json_encode([
                                            'id_descuento' => '-1',
                                            'nombre' => 'PROMO_UBER',
                                            'tipo' => 'MONTO',
                                            'aplicacion' => 'ITEM',
                                            'monto' => $discount,
                                            'porcentaje' => null,
                                            'condicion_aplicable'=> 0,
                                            'producto' => $dataItem[0].'_'.$dataItem[1]
                                        ]);

                                        if($discount > $subTotal)
                                            $discount = $subTotal;

                                    }

                                }

                                $items[] = [
                                    'type' => $dataItem[0],
                                    'id' => $dataItem[1],
                                    'name' => $item->title,
                                    'tax' => $dataItem[5],
                                    'quantity' => $item->quantity->amount,
                                    'ingredient' => 0,
                                    'comment' => $commnet,
                                    'sub_total_price' => $subTotal,
                                    'id_pcpp' => null,
                                    'discount' => $discount,
                                    'json_discount' => $jsonDiscount,
                                ];

                                if(isset($item->selected_modifier_groups)){

                                    foreach ($item->selected_modifier_groups as $question) {

                                        $idPcpp = explode('-',$question->external_data)[3];

                                        if(isset($question->selected_items)){

                                            foreach ($question->selected_items as $res) {

                                                $dataResponse = explode('-',$res->external_data);
                                                $discount = 0;
                                                $subTotal = $dataResponse[9]/100;
                                                $jsonDiscount = null;
                                                $pcpRes = DB::connection($data->connect)->table('pos_configuracion_producto')->where('id_pos_configuracion_producto',$dataResponse[3])->first();

                                                //EXISTEN PROMOCIONES EN LA RESPUESTA
                                                if(isset($response->order->payment->tax_reporting->breakdown->promotions)){

                                                    $arrItemsPromo = array_filter($response->order->payment->tax_reporting->breakdown->promotions, function($itemPromo) use ($res){
                                                        return  $res->cart_item_id === $itemPromo->instance_id && $itemPromo->description === 'ITEM_PROMOTION';
                                                    });

                                                    foreach($arrItemsPromo as $itemPromo){

                                                        $discount = number_format(($itemPromo->net_amount->amount_e5/100000)*-1,2,'.','');

                                                        $jsonDiscount = json_encode([
                                                            'id_descuento' => '-1',
                                                            'nombre' => 'PROMO_UBER',
                                                            'tipo' => 'MONTO',
                                                            'aplicacion' => 'ITEM',
                                                            'monto' => $discount,
                                                            'porcentaje' => null,
                                                            'condicion_aplicable'=> 0,
                                                            'producto' => $dataResponse[0].'_'.$dataResponse[1]
                                                        ]);

                                                    }

                                                    if($discount > $subTotal)
                                                        $discount = $subTotal;

                                                }

                                                $items[] = [
                                                    'type' => $dataResponse[0],
                                                    'id' => $pcpRes->id_producto,
                                                    'name' => $res->title,
                                                    'ingredient' => 1,
                                                    'tax' => $dataResponse[7],
                                                    'quantity' => $res->quantity->amount*$item->quantity->amount,
                                                    'id_pcpp' => $idPcpp,
                                                    'sub_total_price' => $subTotal,
                                                    'discount' => $discount,
                                                    'comment' => '',
                                                    'json_discount' => $jsonDiscount,
                                                ];

                                            }

                                        }

                                    }

                                }

                            }

                        }

                        $createOrder = MpFunctionController::createMpOrder(new Request([
                            'id_branch_office' => $store->id_sucursal,
                            'order_id' => $response->order->id,
                            'connect' => base64_encode($data->connect),
                            'name' => $response->order->ordering_platform.' '.$response->order->display_id,
                            'ordering_platform' => $response->order->ordering_platform,
                            'customer' => $customer,
                            'customer_identification' => $customerIdentification,
                            'customer_address' => $customerAddress,
                            'customer_email' => $customerEmail,
                            'customer_phone' => $customerPhone,
                            'app_deliverys' => true,
                            'total' => $response->order->payment->payment_detail->order_total->gross->amount_e5/100000,
                            'payment_type_id' => $store->id_tipo_pago_uber,
                            'sale_type_id' => $store->id_tipo_venta_uber,
                            'items' => json_encode($items,JSON_NUMERIC_CHECK | JSON_PRESERVE_ZERO_FRACTION),
                            'body' => json_encode($response)
                        ]));

                        $createOrder = $createOrder->getData(true);

                        if(!$createOrder['success']){

                            info('Error orderNotification UBER: ');
                            info($createOrder['msg']);

                        }

                    //ACTUALZA LA INFORMACION DE LA PRECUENTA
                    }else if(in_array($data->event_type,['delivery.state_changed','orders.release'])){

                        $status = $response->order->state;

                        if($data->event_type === 'orders.release'){

                            $status = $response->order->preparation_status;

                        }else if($data->event_type === 'delivery.state_changed'){

                            if(isset($data->meta->current_state) && $data->meta->current_state !== 'SCHEDULED'){

                                $status = $data->meta->current_state;

                            }else if(isset($data->meta->status) && $data->meta->status !== 'SCHEDULED'){

                                $status = $data->meta->status;

                            }

                        }

                        $updateOrder = MpFunctionController::updateMpOrderAppDelivery(new Request([
                            'order_id' => $response->order->id,
                            'status' => $status,
                            'ordering_platform' => $response->order->ordering_platform,
                            'body' => json_encode($response),
                            'connect' => base64_encode($data->connect),
                            'tiempo_preparacion' => 10// isset($response->order->preparation_time) ? $response->order->preparation_time->ready_for_pickup_time_secs/60 : 10
                        ]));

                        $updateOrder = $updateOrder->getData(true);

                        if(!$updateOrder['success']){

                            info('Error updateMpOrderAppDelivery UBER: ');
                            info($updateOrder['msg']);

                        }

                    }

                }else{

                    //NO SE RECIBIÓ EL ORDER EN LA PETICION
                    info('No se recibió el order de uber en la petición '.($data->resource_href.'?'.$params));

                }

            }else{

                info('El webhook de uber no tiene la propiedad order :');
                info(json_encode($data));
                info('$response:');
                info(json_encode($response));
            }

        } catch (\Exception $e) {

            info('Error orderNotification UBER: '.$e->getMessage().' '.$e->getLine().' '.$e->getFile());

        }

    }

    public static function orderNotificationFailure(Object $data)
    {
        try {

            $cancelOrder = MpFunctionController::cancelMpOrderAppDelivery(new Request([
                'order_id' => $data->meta->resource_id,
                'connect' => base64_encode($data->connect),
            ]));

            $cancelOrder = $cancelOrder->getData(true);

            if(!$cancelOrder['success']){

                info('Error orderNotificationFailure UBER: ');
                info($cancelOrder['msg']);

            }

        } catch (\Exception $e) {

            info('Error orderNotificationFailure UBER: '.$e->getMessage().' '.$e->getLine().' '.$e->getFile());

        }

    }

}

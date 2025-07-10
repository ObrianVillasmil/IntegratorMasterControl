<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SecretWebHookRappi;
use App\Models\WebhookRappi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RappiWebhookcontroller extends Controller
{
    public function newOrder(Request $request)
    {
        info("WEBHOOK RECEPCION DE PEDIDO RAPPI:");
        info($request->__toString()."\n");

        $success = true;
        $msg = 'Se ha gurado la orden con éxito';

        try {

            $request->query->add(['event' => 'NEW_ORDER']);

            $secret = SecretWebHookRappi::where('event','ORDER_OTHER_EVENT')->first();

            $validSign = self::validateSignature($request,$secret);

            if(!$validSign['success']){

                info("Unauthorized: {$validSign['msg']}");
                return response("Unauthorized: {$validSign['msg']}",401);

            }

            WebhookRappi::create(['order' => $request->getContent()]);
            $request = json_decode($request->getContent());

            $company = Company::where('token',$request->store->internal_id)->first();

            $store = DB::connection($company->connect)->table('sucursal_tienda_rappi as strappi')
            ->join('sucursal as s','s.id_sucursal','strappi.id_sucursal')
            ->where('strappi.store_id',$request->store->internal_id)->first();

            $customerIdentification = null;
            $customerEmail = 'a@gmail.com';
            $customer = null;
            $customerAddress = null;
            $customerPhone = null;
            $items = [];
            $subtotalNet = 0;

            if($request->customer){

                if(isset($request->customer->document_number))
                    $customerIdentification = $request->customer->document_number;

                if(isset($request->customer->email))
                    $customerEmail = $request->customer->email;

                if(isset($request->customer->first_name))
                    $customer = $request->customer->first_name;

                if(isset($request->customer->last_name))
                    $customer = $customer.' '.$request->customer->last_name;

                if(isset($request->customer->phone_number))
                    $customerPhone = $request->customer->phone_number;

            }

            if(isset($request->order_detail->billing_information)){

                if(isset($request->order_detail->billing_information->document_number))
                    $customerIdentification = $request->order_detail->billing_information->document_number;

                if(isset($request->order_detail->billing_information->email))
                    $customerEmail = $request->order_detail->billing_information->email;

                if(isset($request->order_detail->billing_information->name))
                    $customer = $request->order_detail->billing_information->name;

                if(isset($request->order_detail->billing_information->phone))
                    $customerPhone = $request->order_detail->billing_information->phone;

                if(isset($request->order_detail->billing_information->address))
                    $customerAddress = $request->order_detail->billing_information->address;

            }

            if(isset($request->order_detail->items) && is_array($request->order_detail->items)){

                foreach ($request->order_detail->items as $item) {

                    $dataItem = explode('-',$item->sku);
                    $comment = '';
                    $discount = 0;

                    $imp = DB::connection($company->connect)->table('pos_configuracion_producto as pcp')
                    ->join('impuesto as i', 'pcp.id_impuesto','i.id_impuesto')
                    ->where('pcp.id_pos_configuracion_producto', $dataItem[2])
                    ->select('i.valor')->first();

                    $totalProd = $item->price;
                    $subTotal = number_format(($item->price/(1+($imp->valor/100))),3,'.','');
                    $subtotalProd = $subTotal*$item->quantity;
                    $subtotalNet+= $subtotalProd;
                    $jsonDiscount = null;

                    if(isset($product['comment']))
                        $comment = $product['comment'];

                    // EXISTEN DESCUENTOS
                    if(isset($request->order_detail->discounts) && is_array($request->order_detail->discounts)){

                        $arrDiscount = [
                            'id_descuento' => '-1',
                            'nombre' =>'',
                            'tipo' => '',
                            'aplicacion' => 'ITEM',
                            'monto' => 0,
                            'porcentaje' => 0,
                            'condicion_aplicable'=> 0,
                            'producto' => $dataItem[3].'_'.$dataItem[4]
                        ];

                        //HAY PRODUCTOS CON DESCUENTOS
                        $prodsDesc = array_filter($request->order_detail->discounts, function($arr) use($item){
                            return $arr->type === 'offer_by_product' && $item->sku === $arr->sku;
                        });

                        $x = 0;

                        if(isset($item->subitems) && is_array($item->subitems)){

                            $ttSt = array_reduce($item->subitems, function($sum, $si){ return $sum + $si->price; }, 0);
                            $totalProd = ($item->price + $ttSt) * $item->quantity;

                        }

                        foreach ($prodsDesc as $desc) {

                            if($x == $desc->discount_product_units){
                                unset($prodsDesc);
                                break;
                            }

                            $descTotalProd = ($totalProd*($desc->raw_value/100));

                            if(number_format($descTotalProd,2) == number_format($desc->value,2)){

                                //if($desc->value_type === 'percentage'){

                                $discount+= $desc->value;
                                $arrDiscount['nombre'].= ($desc->title." | $".$desc->value." ");
                                $arrDiscount['porcentaje']+= $desc->raw_value;
                                $arrDiscount['tipo'] = 'PORCENTAJE';
                                $subtotalNet-=($subtotalProd*($desc->raw_value/100));
                                $x++;

                                /*} else{

                                    $discount = $desc->value;
                                    $arrDiscount['monto']+= $desc->value;
                                    $arrDiscount['tipo'] = 'MONTO';
                                    $subtotalNet-= $desc->value;

                                } */

                            }

                        }

                        $jsonDiscount = json_encode($arrDiscount);

                    }

                    $items[] = [
                        'type' => $dataItem[3],
                        'id' => $dataItem[4],
                        'name' => $item->name,
                        'tax' => $imp->valor,
                        'quantity' => $item->quantity,
                        'ingredient' => 0,
                        'comment' => $comment,
                        'sub_total_price' => $subTotal,
                        'id_pcpp' => null,
                        'discount' => $discount,
                        'json_discount' => $jsonDiscount,
                    ];

                    if(isset($item->subitems) && is_array($item->subitems)){

                        $jsonDiscount = null;

                        foreach ($item->subitems as $subItem) {

                            $dataSubItem = explode('-',$subItem->sku);

                            $pcpRes = DB::connection($company->connect)->table('pos_configuracion_producto as pcp')
                            ->join('impuesto as imp','pcp.id_impuesto','imp.id_impuesto')
                            ->where('id_pos_configuracion_producto',$dataSubItem[3])
                            ->select('pcp.id_producto','imp.valor', 'pcp.tabla')->first();

                            $subTotal = number_format(($subItem->price/(1+($pcpRes->valor/100))),3,'.','');
                            $quantitySi = $item->quantity*$subItem->quantity;
                            $subtotalProd = $subTotal*$quantitySi;
                            $subtotalNet+= $subTotal*$quantitySi;

                            if($subItem->price > 0 && isset($prodsDesc) && count($prodsDesc) && $prodsDesc[0]->includes_toppings){

                                $arrDiscount['producto'] = ($pcpRes->tabla === 'receta' ? 'R' : 'I').'_'.$pcpRes->id_producto;
                                $jsonDiscount = json_encode($arrDiscount);

                                if($prodsDesc[0]->value_type === 'percentage'){

                                    $subtotalNet-= ($subtotalProd*($prodsDesc[0]->raw_value/100));

                                }else{

                                    $subtotalNet-= $prodsDesc[0]->value;
                                }

                            }

                            $items[] = [
                                'type' => $pcpRes->tabla === 'receta' ? 'R' : 'I',
                                'id' => $pcpRes->id_producto,
                                'name' => $subItem->name,
                                'ingredient' => 1,
                                'tax' => $pcpRes->valor,
                                'quantity' => $quantitySi,
                                'id_pcpp' => $dataSubItem[7],
                                'sub_total_price' => $subTotal,
                                'discount' => 0,
                                'comment' => '',
                                'json_discount' => $jsonDiscount
                            ];

                        }

                    }

                }

            }

            if(isset($request->order_detail->discounts) && is_array($request->order_detail->discounts)){

                //HAY DESCUENTOS AL TOTAL
                $discountsTotal = [
                    'id_descuento' => "descuento_".strtoupper(str_replace('.','',uniqid('',true))),
                    'nombre' => "",
                    'tipo' => "MONTO",
                    'porcentaje' => "",
                    'monto' => 0,
                    'id_rol' => "",
                    'cantidad_aplicable' => "0",
                    'id_producto_x' => "",
                    'cant_producto_x' => "",
                    'id_producto_y' => "",
                    'cant_producto_y' => "",
                    'n_producto' => "",
                    'monto_consumir' => "",
                    'tipo_producto_x' => "",
                    'tipo_producto_y' => "",
                    'condicion_aplicable' => "1",
                    'productos' => []
                ];

                $dsctosTotal = array_filter($request->order_detail->discounts, function($arr){
                    return !$arr->sku && $arr->type !== 'free_shipping';
                });

                $dt = 0;
                foreach ($dsctosTotal as $desc) {

                    $dt+= $desc->value;

                    $discountsTotal['nombre'] .= ($desc->title." | $".$desc->value." ");

                    //CALCULA EL PORCENTAJE DE DESCUENTO AL TOTAL
                    $percentage = ($desc->value*100)/$request->order_detail->totals->total_products_with_discount;

                    //CALCULA EL PORCENTAJE DE DESCUENTO AL SUB TOTAL
                    info('$subtotalNet '.$subtotalNet);
                    $discountsTotal['monto'] += number_format(($subtotalNet*$percentage)/100,2,'.','');

                }

            }

            $createOrder = MpFunctionController::createMpOrder(new Request([
                'id_branch_office' => $store->id_sucursal,
                'order_id' => $request->order_detail->order_id,
                'connect' => base64_encode($company->connect),
                'name' => 'RAPPI '.$request->order_detail->order_id,
                'ordering_platform' => 'RAPPI',
                'customer' => $customer,
                'customer_identification' => $customerIdentification,
                'customer_address' => $customerAddress,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'app_deliverys' => true,
                'total' => $request->order_detail->totals->total_products_with_discount - $dt,
                'payment_type_id' => $store->id_tipo_pago_rappi,
                'sale_type_id' => $store->id_tipo_venta_rappi,
                'items' => json_encode($items,JSON_NUMERIC_CHECK|JSON_PRESERVE_ZERO_FRACTION),
                'body' => json_encode($request),
                'json_desc_subtotal' => isset($discountsTotal) ? [$discountsTotal] : null
            ]));

            $createOrder = $createOrder->getData(true);

            if(!$createOrder['success']){

                //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                info('Error createNewOrder RAPPI: ');
                info($createOrder['msg']);
                throw new \Exception($createOrder['msg']);

            }

            return response("",200);

        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().' '.$e->getLine().' '.$e->getFile();
            info('Error createNewOrder RAPPI: ');
            info($msg);
        }

        return [
            'success' => $success,
            'msg' => $msg
        ];


    }

    public function orderEventCancel(Request $request)
    {
        info('orderEventCancel RAPPI');
        info("\n ".$request->__toString());

         try {

            $request->query->add(['event' => 'ORDER_EVENT_CANCEL']);

            $secret = SecretWebHookRappi::where('event','ORDER_EVENT_CANCEL')->first();

            $validSign = self::validateSignature($request,$secret);

            if(!$validSign['success']){

                info("Unauthorized: \n {$validSign['msg']}");
                return response("Unauthorized: {$validSign['msg']}",401);

            }

            WebhookRappi::create(['order' => $request->getContent()]);
            $request = json_decode($request->getContent());

            $company = Company::where('token',$request->store_id)->first();

            $updateOrder = MpFunctionController::cancelMpOrderAppDelivery(new Request([
                'order_id' => $request->order_id,
                'status' => $request->event,
                'connect' => base64_encode($company->connect),
            ]));

            $updateOrder = $updateOrder->getData(true);

            if(!$updateOrder['success']){

                info('Error cancelOrder RAPPI: ');
                info($updateOrder['msg']);
                $msg = $updateOrder['msg'];
                $success = false;

            }

            $success =true;
            $msg = $updateOrder['msg'];
            info($msg);
        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().' '.$e->getLine().' '.$e->getFile();
            info('Error orderEventCancel RAPPI: ');
            info($msg);
        }

        return [
            'success' => $success,
            'msg' => $msg
        ];

    }

    public function orderOtherEvent(Request $request)
    {
        info('orderOtherEvent RAPPI');
        info("\n ".$request->__toString());

        try {

            $secret = SecretWebHookRappi::where('event','ORDER_OTHER_EVENT')->first();

            $validSign = self::validateSignature($request, $secret);

            if(!$validSign['success']){

                info("Unauthorized: \n {$validSign['msg']}");
                return response("Unauthorized: {$validSign['msg']}",401);

            }

            WebhookRappi::create(['order' => $request->getContent()]);
            $request = json_decode($request->getContent());

            $company = Company::where('token',$request->store_id)->first();

            $precuenta = DB::connection($company->connect)->table('precuenta')->where('default_name',$request->order_id)->first();

            $precAppDelivery = DB::connection($company->connect)->table('precuenta_app_delivery')
            ->where('id_precuenta',$precuenta->id_precuenta)
            ->where('estado',true)->first();

            $cuerpo = json_decode($precAppDelivery->cuerpo);

            //AGREGAR EL STATUS DEL PEDIDO AL JSON
            $cuerpo->current_status = $request->event;

            //AGREGA EL CODIGO DE ENTREGA AL JSON
            if($request->event === 'taken_visible_order'){

                $sucursal = DB::connection($company->connect)->table('sucursal')
                ->join('empresa as e','e.id_empresa','sucursal.id_empresa')
                ->join('sucursal_tienda_rappi as st','st.id_sucursal','sucursal.id_sucursal')
                ->where('sucursal.id_sucursal',$precuenta->id_sucursal)
                ->select('e.url_rappi','st.token','st.store_id')->first();

                $res = self::clientRappiCurl("{$sucursal->url_rappi}/restaurants/orders/v1/stores/{$sucursal->store_id}/orders/{$request->order_id}/handoff",[
                    'token' => $sucursal->token,
                ],'GET');

                $cuerpo->product_confirmation_code = $res['response']->product_confirmation_code;

            }

            $updateOrder = MpFunctionController::updateMpOrderAppDelivery(new Request([
                'order_id' => $request->order_id,
                'status' => $request->event,
                'ordering_platform' => 'RAPPI',
                'body' => json_encode($cuerpo),
                'connect' => base64_encode($company->connect),
                'tiempo_preparacion' => $precAppDelivery->tiempo_preparacion
            ]));

            $updateOrder = $updateOrder->getData(true);

            if(!$updateOrder['success']){

                info('Error updateOrder RAPPI: ');
                info($updateOrder['msg']);
                $msg = $updateOrder['msg'];
                $success = false;

            }

            $success =true;
            $msg = $updateOrder['msg'];
            info($msg);
        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().' '.$e->getLine().' '.$e->getFile();
            info('Error updateOrder RAPPI: ');
            info($msg);
        }

        return [
            'success' => $success,
            'msg' => $msg
        ];


        return response("",200);

        return response("",200);
    }

    public function orderRtTracking(Request $request)
    {
        /* info('orderRtTracking RAPPI');
        info("\n ".$request->__toString()); */

        return response("",200);
    }

    public function menuApproved(Request $request)
    {
        info('menuApproved RAPPI');
        info("\n ".$request->__toString());

        $request->query->add(['event' => 'MENU_APPROVED']);

        $secret = SecretWebHookRappi::where('event','MENU_APPROVED')->first();

        $validSign = self::validateSignature($request, $secret);

        if(!$validSign['success']){

            info("Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized \n {$validSign['msg']}",401);

        }

        return response("",200);
    }

    public function menuRejected(Request $request)
    {
        info('menuRejected RAPPI');
        info("\n ".$request->__toString());

        $request->query->add(['event' => 'MENU_REJECTED']);

        $secret = SecretWebHookRappi::where('event','MENU_REJECTED')->first();

        $validSign = self::validateSignature($request,$secret);

        if(!$validSign['success']){

            info("Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized \n {$validSign['msg']}",401);

        }

        return response("",200);
    }

    public function pingRappi(Request $request)
    {
        try {

            $request->query->add(['event' => 'PING']);

            $secret = SecretWebHookRappi::where('event','PING')->first();

            $validSign = self::validateSignature($request ,$secret);

            if(!$validSign['success']){

                info("Unauthorized: {$validSign['msg']}");
                return response("Unauthorized: {$validSign['msg']}",401);

            }

            $request = json_decode($request->getContent());

            $company = Company::where('token',$request->store_id)->first();

            if(self::pingMp($company->connect)){

                info("PING RAPPI: OK {$company->error_email}");

                return response()->json([
                    "status"=> "OK",
                    "description"=> "Tienda prendida"
                ],200);

            }else{

                info("PING RAPPI: KO {$company->error_email}");

                 return response()->json([
                     "status"=> "KO",
                    "description"=> "Tienda apagada"
                ],500);

            }

        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().' '.$e->getLine().' '.$e->getFile();
            info('Error orderEventCancel RAPPI: ');
            info($msg);
        }

        return [
            'success' => $success,
            'msg' => $msg
        ];

    }

    public function storeConnectvity(Request $request)
    {
        info('storeConnectvity RAPPI');
        info("\n ".$request->__toString());

        $request->query->add(['event' => 'STORE_CONNECTIVITY']);

        $secret = SecretWebHookRappi::where('event','STORE_CONNECTIVITY')->first();

        $validSign = self::validateSignature($request, $secret);

        if(!$validSign['success']){

            info("Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized: {$validSign['msg']}",401);

        }

        $request = json_decode($request->getContent());

        if(!$request->enabled){

            $company = Company::where('token',$request->external_store_id)->first();

            ContificoIntegrationController::sendMail([
                'subject' => "TIENDA DE RAPPI APAGADA",
                'sucursal' => strtoupper($company->connect),
                'ccEmail' => env('MAIL_NOTIFICATION'),
                'html' => "<html>
                    <head>
                        <style>
                            .alert {
                                padding: 15px;
                                margin-bottom: 20px;
                                border: 1px solid transparent;
                                border-radius: 4px;
                            }
                            .alert-danger {
                                color: #155724;
                                background-color: #d4edda;
                                border-color: #c3e6cb;
                            }
                        </style>
                    </head>
                    <body>
                        <div class='alert alert-danger' role='alert'>
                            <p> La tienda {$company->error_email} está apagada en la aplicación de usuarios de RAPPI</p>
                            <p> Mensaje: {$request->message}</p>
                        </div>
                    </body>
                </html>"
            ]);

        }

        return response("",200);
    }

    private static function validateSignature(Request $request, $secret)
    {
        try {

            if(!$request->event)
                throw new \Exception("No obtuvo el evento");

            $signature = $request->header('Rappi-Signature');

            if(!isset($secret))
                throw new \Exception("No se ha configurado el secret del evento {$request->event}");

            if(!$signature)
                throw new \Exception('No se ha recibido la firma de la petición');

            $arrSignature = explode(',', $signature);

            if(count($arrSignature) != 2)
                throw new \Exception('El formato de la firma en la petición no es válida');

            $t = null;
            $sign = null;

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

            $success = hash_hmac('sha256', $signedPayload, $secret->secret) === $sign;

            if(!$success){

                info("Verificacion de firma Rappi: ");
                info("signedPayload \n {$signedPayload}");
                info("request->secret \n {$secret->secret}");
                info("sign \n {$sign}");
                info("hmac: \n ".hash_hmac('sha256', $signedPayload, $secret->secret)."\n");
                throw new \Exception("La firma no es válida");

            }

            return ['success' => $success];

        } catch (\Exception $e) {

            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];

        }

    }

    private static function clientRappiCurl(String $url, Array $data, String $metodo = 'POST')
    {
        $header = [
            "Content-Type: application/json",
            'x-authorization: Bearer '.$data['token'],
            'Accept: application/json',
        ];

        unset($data['token']);

        $cliente = curl_init();

        if(!isset($data[0])){

            $params = json_encode($data);

        }else{

            $params = json_encode($data[0]);

        }
        //dd($url,$params);
        if(count($data))
            curl_setopt($cliente, CURLOPT_POSTFIELDS, $params);

        curl_setopt($cliente, CURLOPT_URL, $url);

        curl_setopt($cliente, CURLOPT_HTTPHEADER, $header);
        curl_setopt($cliente, CURLOPT_CUSTOMREQUEST, $metodo);
        curl_setopt($cliente, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cliente, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($cliente, CURLOPT_CONNECTTIMEOUT, 15);

        $response = curl_exec($cliente);

        $codigoHttp = curl_getinfo($cliente, CURLINFO_HTTP_CODE);

        curl_close($cliente);

        $response = json_decode($response);

        return [
            'code' => $codigoHttp,
            'response' => $response
        ];
    }

}

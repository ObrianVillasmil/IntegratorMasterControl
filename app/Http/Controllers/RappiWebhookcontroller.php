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
        info("newOrder RAPPI");
        info("Info recibida: \n\n ".$request->__toString());

        $success = true;
        $msg = 'Se ha gurado la orden con éxito';

        try {

            $request->query->add(['event' => 'NEW_ORDER']);

            $validSign = self::validateSignature($request);

            if(!$validSign['success']){

                info("Unauthorized: \n {$validSign['msg']}");
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

            if($request->customer && is_object($request->customer)){

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

            if(isset($request->billing_information) && is_object($request->billing_information)){

                if(isset($request->billing_information->document_number))
                    $customerIdentification = $request->billing_information->document_number;

                if(isset($request->billing_information->email))
                    $customerEmail = $request->billing_information->email;

                if(isset($request->billing_information->name))
                    $customer = $request->billing_information->name;

                if(isset($request->billing_information->phone))
                    $customerPhone = $request->billing_information->phone;

                if(isset($request->billing_information->address))
                    $customerAddress = $request->billing_information->address;

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

                    $subTotal = number_format(($item->price/(1+($imp->valor/100))),3,'.','');

                    $subtotalNet+= $subTotal*$item->quantity;
                    $jsonDiscount = null;

                    if(isset($product['comment']))
                        $comment = $product['comment'];

                    // EXISTEN DESCUENTOS
                    if(isset($request->order_detail->discounts) && is_array($request->order_detail->discounts)){

                        //HAY PRODUCTOS CON DESCUENTOS
                        $prodsDesc = array_filter($request->discounts, function($arr) use($item){
                            return $arr->type ==='offer_by_product' && $item->sku === $arr->sku;
                        });

                        foreach ($prodsDesc as $desc) {

                            $discount = $desc->value;

                            $jsonDiscount = json_encode([
                                'id_descuento' => '-1',
                                'nombre' => $desc->title,
                                'tipo' => $desc->value_type ==='percentage' ? 'PORCENTAJE' : 'MONTO',
                                'aplicacion' => 'ITEM',
                                'monto' => $discount,
                                'porcentaje' => null,
                                'condicion_aplicable'=> 0,
                                'producto' => $dataItem[2].'_'.$dataItem[3]
                            ]);

                            if($discount > $subTotal)
                                $discount = $subTotal;

                        }

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

                        foreach ($item->subitems as $subItem) {

                            $dataSubItem = explode('-',$subItem->sku);

                            $pcpRes = DB::connection($company->connect)->table('pos_configuracion_producto as pcp')
                            ->join('impuestos as imp','pcp.id_impuesto','imp.id_impuesto')
                            ->where('id_pos_configuracion_producto',$dataSubItem[3])
                            ->select('pcp.id_producto','impuestos.valor')->first();

                            $subTotal = number_format(($subItem->price/(1+($pcpRes->valor/100))),3,'.','');
                            $subtotalNet+= $subTotal*$subItem->quantity;

                            $items[] = [
                                'type' => $pcpRes->tabla === 'receta' ? 'R' : 'I',
                                'id' => $pcpRes->id_producto,
                                'name' => $subItem->name,
                                'ingredient' => 1,
                                'tax' => $pcpRes->valor,
                                'quantity' => $item->quantity*$subItem->quantity,
                                'id_pcpp' => $dataSubItem[7],
                                'sub_total_price' => $subTotal,
                                'discount' => 0,
                                'comment' => '',
                                'json_discount' =>null ,
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

                foreach ($dsctosTotal as $desc) {

                    $discountsTotal['nombre'] .= ($desc->title." | $".$desc->value." ");

                    //CALCULA EL PORCENTAJE DE DESCUENTO AL TOTAL
                    $percentage = ($desc->value*100)/$request->order_detail->totals->total_products_with_discount;

                    //CALCULA EL PORCENTAJE DE DESCUENTO AL SUB TOTAL
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
                'total' => $request->order_detail->totals->total_products_with_discount - (isset($discountsTotal) ? $discountsTotal['monto'] : 0),
                'payment_type_id' => $store->id_tipo_pago_rappi,
                'sale_type_id' => $store->id_tipo_venta_rappi,
                'items' => json_encode($items,JSON_NUMERIC_CHECK|JSON_PRESERVE_ZERO_FRACTION),
                'body' => json_encode($request->all()),
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
            $msg = $e->getMessage().''.$e->getLine().''.$e->getFile();

        }

        return [
            'success' => $success,
            'msg' => $msg
        ];


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

        $request->query->add(['event' => 'MENU_APPROVED']);

        $validSign = self::validateSignature($request);

        if(!$validSign['success']){

            info("Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized \n {$validSign['msg']}",401);

        }

        return response("",200);
    }

    public function menuRejected(Request $request)
    {
        info('menuRejected RAPPI');
        info("Info recibida: \n\n ".$request->__toString());

        $request->query->add(['event' => 'MENU_REJECTED']);

        $validSign = self::validateSignature($request);

        if(!$validSign['success']){

            info("Unauthorized: \n {$validSign['msg']}");
            return response("Unauthorized \n {$validSign['msg']}",401);

        }

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

            if(!$request->event)
                throw new \Exception("No obtuvo el evento");

            $signature = $request->header('Rappi-Signature');

            $secret = SecretWebHookRappi::where('event',$request->event)->first();

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

}

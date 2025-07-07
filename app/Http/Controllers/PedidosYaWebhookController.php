<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\WebhookPedidoya;
use Firebase\JWT\{JWT, Key};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidosYaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        try {

            info(" WEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n");

            $stringReq = $request->__toString();

            $path = $request->path();

            info($stringReq."\n");

            $arrPath = explode('/',$path);

            $vendorId = null;

            $remoteIds = Company::whereNotNull('secret_key_pedidosya')->select('token')->get()->pluck('token')->toArray();

            foreach($arrPath as $string){

                if(in_array(trim($string),$remoteIds)){

                    $vendorId = trim($string);
                    break;

                }

            }

            if(!isset($vendorId))
                throw new \Exception("No se ha encontrado el vendorId en la petición de PedidosYa");


            $company = Company::where('token',$vendorId)->first();

            if(!$company)
                throw new \Exception("No se ha encontrado la empresa registrada con el vendorId: {$vendorId}");

            if(!$company->secret_key_pedidosya)
                throw new \Exception("No se ha registrado la clave secreta de pedidosya para la empresa registrada con el vendorId: {$vendorId}");

            $jwt = $request->header('Authorization');

            if(!isset($jwt))
                throw new \Exception("No se ha encontrado el token de autorización en la petición de PedidosYa");

            if(strpos($jwt,'Bearer') === false)
                throw new \Exception("El token de autorización de PedidosYa no es Bearer");

            $token = trim(explode('Bearer ',$jwt)[1]);

            $p1 = json_decode(base64_decode(explode('.',$token)[0]));

            $p2 = json_decode(base64_decode(explode('.',$token)[1]));

            $hJwt = JWT::decode($token, new Key($company->secret_key_pedidosya, $p1->alg));

            if((!isset($p2->iss) || !isset($p2->service) || !isset($hJwt->iss) || !isset($hJwt->service)) || ($hJwt->iss != $p2->iss) || ($hJwt->service != $p2->service))
                throw new \Exception("El token de autorización de PedidosYa no no coincide con la decodificación");

            $random = strtoupper(str_replace('.','',uniqid('',true)));
            $remoteOrderId = "PREC-{$random}";

            $request->query->add([
                'connect' => $company->connect,
                'vendorid' => $vendorId,
                'remoteOrderId' => $remoteOrderId,
                'url' => $request->path()
            ]);

            //RECEPCION DE NUEVA ORDEN
            if(strpos($path,'order/') !== false){

                $request->query->add(['current_status' => 'OFFERED']);

                $response = self::createNewOrder($request);

                if(!$response['success']){
                    //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                    throw new \Exception($response['msg']);
                }

                return response(['remoteResponse'=> ['remoteOrderId' => $remoteOrderId]],200,['Content-Type' => 'application/json']);

            }else if(strpos($path,'posOrderStatus') !== false){ //ACTUALIZACION DE ESTADO DE LA ORDEN

                $response = self::updateOrder($request);
                info("self::updateOrder \n");
                info(print_r($response,true));
                if(!$response['success']){
                    //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                    throw new \Exception($response['msg']);
                }

            }

        } catch (\Exception $e) {

            info("Error en la peticion a {$request->path()}: \n\n {$e->getMessage()}");
            return response("Unauthorized",401);
        }

    }

    public static function createNewOrder(Request $request)
    {
        $success = true;
        $msg = 'Se ha gurado la orden con éxito';

        try {

            WebhookPedidoya::create(['order' => json_encode($request->all())]);

            $store = DB::connection($request->connect)->table('sucursal_tienda_peya as stpeya')
            ->join('sucursal as s','s.id_sucursal','stpeya.id_sucursal')
            ->where('stpeya.posvendorid',$request->vendorid)->first();

            $customerIdentification = null;
            $customerEmail = 'a@gmail.com';
            $customer = $request->customer['firstName'].' '.$request->customer['lastName'];
            $customerAddress = null;
            $customerPhone = null;
            $items = [];
            $subtotalNet = 0;

            if(isset($request->corporateTaxId) && $request->corporateTaxId !== '')
                $customerIdentification = $request->corporateTaxId;

            if(isset($request->comments['customerComment']) && $request->comments['customerComment'] !== ''){

                $arrCustomerComment = explode('|',$request->comments['customerComment']);

                foreach($arrCustomerComment as $comment){

                    if(strpos($comment,'Email del Cliente:') === 0)
                        $customerEmail = trim(explode(':',$comment)[1]);

                    if(strpos($comment,'Facturar a empresa:') === 0){

                        $arrCustomer = explode(':',$comment);

                        $customerIdentification = trim($arrCustomer[2]);

                        $customer = trim(explode('-',$arrCustomer[1])[0]);

                    }

                }

            }

            if(isset($request->customer->mobilePhone) && $request->customer->mobilePhone !== '')
                $customerPhone = $request->customer->mobilePhone;

            if(isset($request->delivery->address) && is_array($request->delivery->address))
                $customerAddress = $request->delivery->address;

            if(isset($request->products) && is_array($request->products)){

                foreach ($request->products as $product) {

                    $dataItem = explode('-',$product['remoteCode']);
                    $comment = '';
                    $discount = 0;

                    $imp = DB::connection($request->connect)->table('pos_configuracion_producto as pcp')
                    ->join('impuesto as i', 'pcp.id_impuesto','i.id_impuesto')
                    ->where('pcp.id_pos_configuracion_producto', $dataItem[2])
                    ->select('i.valor')->first();

                    $subTotal = number_format(($product['unitPrice']/(1+($imp->valor/100))),3,'.','');

                    $subtotalNet+= $subTotal*$product['quantity'];
                    $jsonDiscount = null;

                    if(isset($product['comment']))
                        $comment = $product['comment'];

                    $items[] = [
                        'type' => $dataItem[3],
                        'id' => $dataItem[4],
                        'name' => $product['name'],
                        'tax' => $imp->valor,
                        'quantity' => $product['quantity'],
                        'ingredient' => 0,
                        'comment' => $comment,
                        'sub_total_price' => $subTotal,
                        'id_pcpp' => null,
                        'discount' => $discount,
                        'json_discount' => $jsonDiscount,
                    ];


                    if(isset($product['selectedToppings']) && is_array($product['selectedToppings'])){

                        foreach ($product['selectedToppings'] as $topping) {

                            $idPcpp = explode('-',$topping['remoteCode'])[4];

                            foreach ($topping['children'] as $res) {

                                $dataResponse = explode('-',$res['remoteCode']);

                                $pcpRes = DB::connection($request->connect)->table('pos_configuracion_producto as pcp')
                                ->join('impuesto as i', 'pcp.id_impuesto','i.id_impuesto')
                                ->where('pcp.id_pos_configuracion_producto',$dataResponse[3])
                                ->select('pcp.*','i.valor')->first();

                                $discount = 0;
                                $subTotal =  number_format(($res['price']/(1+($pcpRes->valor/100))),3,'.','');

                                $subtotalNet+= $subTotal;
                                $jsonDiscount = null;


                                $items[] = [
                                    'type' => $pcpRes->tabla == 'receta' ? 'R' : 'I',
                                    'id' => $pcpRes->id_producto,
                                    'name' => $res['name'],
                                    'ingredient' => 1,
                                    'tax' => $pcpRes->valor,
                                    'quantity' => $res['quantity']*$product['quantity'],
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

            if(isset($request->discounts) && is_array($request->discounts)){

                $discounts = [
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

                foreach ($request->discounts as $discount)
                    $discounts['nombre'] .= ($discount['name']." $".$discount['amount']." - ");

                //GRACIAS A LOS MALDITOS DE PEDIDOS YA

                //CALCULA EL PORCENTAJE DE DESCUENTO AL TOTAL
                $percentage = ($request->price['discountAmountTotal']*100)/$request->price['subTotal'];

                //CALCULA EL PORCENTAJE DE DESCUENTO AL SUB TOTAL
                $discounts['monto'] = number_format(($subtotalNet*$percentage)/100,2,'.','');

            }

            $createOrder = MpFunctionController::createMpOrder(new Request([
                'id_branch_office' => $store->id_sucursal,
                'order_id' => $request->token,
                'connect' => base64_encode($request->connect),
                'name' => $request->localInfo['platform'].' '.$request->shortCode,
                'ordering_platform' => $request->localInfo['platform'],
                'customer' => $customer,
                'customer_identification' => $customerIdentification,
                'customer_address' => $customerAddress,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'app_deliverys' => true,
                'total' => $request->price['totalNet'] - $request->price['discountAmountTotal'],
                'payment_type_id' => $store->id_tipo_pago_peya,
                'sale_type_id' => $store->id_tipo_venta_peya,
                'items' => json_encode($items,JSON_NUMERIC_CHECK|JSON_PRESERVE_ZERO_FRACTION),
                'body' => json_encode($request->all()),
                'json_desc_subtotal' => isset($discounts) ? [$discounts] : null
            ]));

            $createOrder = $createOrder->getData(true);

            if(!$createOrder['success']){

                //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                info('Error createNewOrder PEDIDOS YA: ');
                info($createOrder['msg']);
                throw new \Exception($createOrder['msg']);

            }


        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().''.$e->getLine().''.$e->getFile();

        }

        return [
            'success' => $success,
            'msg' => $msg
        ];
    }

    public static function updateOrder(Request $request)
    {
        $success = true;
        $msg = 'Se ha actualizado la orden con éxito';

        try {

            $remoteOrderId = explode('/',$request->url)[5];

            $precAppDelivery = DB::connection($request->connect)->table('precuenta_app_delivery')
            ->where('cuerpo->remoteOrderId',$remoteOrderId)
            //->whereIn('estado_app',['ACCEPTED','OFFERED','ORDER_PICKED_UP'])
            ->where('estado',true)->first();

            if(!$precAppDelivery)
                throw new \Exception("No se ha encontrado una orden con el remoteOrderId {$remoteOrderId} en en vendor {$request->vendorid}");

            $cuerpo = json_decode($precAppDelivery->cuerpo);

            if(isset($request->status))
                $cuerpo->current_status = $request->status;

            if($request->status === 'ORDER_CANCELLED'){

                $updateOrder = MpFunctionController::cancelMpOrderAppDelivery(new Request([
                    'order_id' => $cuerpo->token,
                    'canceled_message' => $request->status,
                    'connect' => base64_encode($cuerpo->connect),
                ]));

            }else{

                $updateOrder = MpFunctionController::updateMpOrderAppDelivery(new Request([
                    'order_id' => $cuerpo->token,
                    'status' => $request->status,
                    'ordering_platform' => $cuerpo->localInfo->platform,
                    'body' => json_encode($cuerpo),
                    'connect' => base64_encode($cuerpo->connect),
                    'tiempo_preparacion' => $precAppDelivery->tiempo_preparacion
                ]));

            }

            $updateOrder = $updateOrder->getData(true);

            if(!$updateOrder['success']){

                info('Error updateOrder PEDIDOS YA: ');
                info($updateOrder['msg']);
                $msg = $updateOrder['msg'];
                $success = false;

            }

        } catch (\Exception $e) {

            $success = false;
            $msg = $e->getMessage().' '.$e->getLine().' '.$e->getFile();

        }

        return [
            'success' => $success,
            'msg' => $msg
        ];

    }

}

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

            info("\nWEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n");

            $stringReq = $request->__toString();

            $path = $request->path();

            info('$path: '.$path."\n");

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

            info(print_r($p1,true));
            info(print_r($p2,true));

            $hJwt = JWT::decode($token, new Key($company->secret_key_pedidosya, $p1->alg));

            info(print_r($hJwt,true));

            if((!isset($p2->iss) || !isset($p2->iat) || !isset($hJwt->iss) || !isset($hJwt->iat)) || ($hJwt->iss != $p2->iss) || ($hJwt->iat != $p2->iat))
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
                    $commnet = '';
                    $discount = 0;
                    $subTotal = number_format(($product['unitPrice']/(1+($dataItem[6]/100))),2,'.','');
                    $jsonDiscount = null;

                    if(isset($product['comment']))
                        $commnet = $product['comment'];

                    $items[] = [
                        'type' => $dataItem[3],
                        'id' => $dataItem[4],
                        'name' => $product['name'],
                        'tax' => $dataItem[6],
                        'quantity' => $product['quantity'],
                        'ingredient' => 0,
                        'comment' => $commnet,
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
                                $discount = 0;
                                $subTotal =  number_format(($res['price']/(1+($dataResponse[7]/100))),2,'.','');
                                $jsonDiscount = null;
                                $pcpRes = DB::connection($request->connect)->table('pos_configuracion_producto')->where('id_pos_configuracion_producto',$dataResponse[3])->first();

                                $items[] = [
                                    'type' => $pcpRes->tabla == 'receta' ? 'R' : 'I',
                                    'id' => $pcpRes->id_producto,
                                    'name' => $res['name'],
                                    'ingredient' => 1,
                                    'tax' => $dataResponse[7],
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
                'total' => $request->price['totalNet'],
                'payment_type_id' => $request->payment['type'] === 'Efectivo' ? 1 : $store->id_tipo_pago_peya,
                'sale_type_id' => $store->id_tipo_venta_peya,
                'items' => json_encode($items,JSON_NUMERIC_CHECK|JSON_PRESERVE_ZERO_FRACTION),
                'body' => json_encode($request->all())
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

            $remoteOrderId = explode('/',$request->url)[6];
            $vendorId = explode('/',$request->url)[4];

            $company = Company::where('token',$vendorId)->first();

            $precAppDelivery = DB::connection($company->connect)->table('precuenta_app_delivery')
            ->where('cuerpo->remoteOrderId',$remoteOrderId)
            ->whereIn('estado_app',['ACCEPTED','OFFERED'])
            ->where('estado',true)->first();

            if(!$precAppDelivery)
                throw new \Exception("No se ha encontrado una orden con el remoteOrderId {$remoteOrderId} en en vendor {$vendorId}");

            $cuerpo = json_decode($precAppDelivery->cuerpo);

            if(isset($request->status))
                $cuerpo->current_status = $request->status;

            if(isset($request->message))
                $cuerpo->canceled_message = $request->message;

            $updateOrder = MpFunctionController::updateMpOrderAppDelivery(new Request([
                'order_id' => $cuerpo->token,
                'status' => $request->status,
                'ordering_platform' => $cuerpo->localInfo['platform'],
                'body' => json_encode($cuerpo),
                'connect' => base64_encode($cuerpo->connect),
                'tiempo_preparacion' => $precAppDelivery->tiempo_preparacion
            ]));

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

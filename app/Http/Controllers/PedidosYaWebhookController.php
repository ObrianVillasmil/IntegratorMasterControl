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

            info($stringReq."\n");

            $arrPath = explode('order/',$path);

            info($path."\n");

            info(print_r($arrPath,true)."\n");

            if(!isset($arrPath[1])){

                $arrPath = explode('remoteId/',$path);

                if(!isset($arrPath[1]))
                    throw new \Exception("No se ha encontrado el vendorId en la petición de PedidosYa");

            }

            $vendorId = $arrPath[1];

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

            if((!isset($p2->iss) || !isset($p2->iat) || !isset($hJwt->iss) || !isset($hJwt->iat)) || ($hJwt->iss != $p2->iss) || ($hJwt->iat != $p2->iat))
                throw new \Exception("El token de autorización de PedidosYa no no coincide con la decodificación");

            $request->query->add([
                'connect' => $company->connect,
                'vendorid' => $vendorId
            ]);

            //RECEPCION DE NUEVA ORDEN
            info('order/'.': '.strpos($path,'order/')."\n");

            if(strpos($path,'order/') !== false){

                $response = self::createNewOrder($request);

                info(print_r($response,true)."\n");

                if(!$response['success']){
                    //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                }

            }else if(strpos($path,'posOrderStatus/') !== false){ //ACTUALIZACION DE ESTADO DE LA ORDEN

                $response = self::updateOrder($request);

                if(!$response['success']){
                    //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                }

            }

            //info($request->all());

            return response("",200);

        } catch (\Exception $e) {

            info("Error en la peticion a /integracion-peya/order: \n\n {$e->getMessage()}");
            return response("Unauthorized",403);
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
            $customer = null;
            $customerAddress = null;
            $customerPhone = null;
            $items = [];

            if(isset($request->corporateTaxId) && $request->corporateTaxId !== '')
                $customerIdentification = $request->corporateTaxId;

            if(isset($request->comments->customerComment) && $request->comments->customerComment !== ''){

                $arrCustomerComment = explode('|',$request->comments->customerComment);

                foreach($arrCustomerComment as $comment){

                    if(strpos($comment,'Email del Cliente:') === 0)
                        $customerEmail = trim(explode(':',$comment)[1]);

                }

            }

            if(isset($request->customer->mobilePhone) && $request->customer->mobilePhone !== '')
                $customerPhone = $request->customer->mobilePhone;

            if(isset($request->delivery->address) && is_array($request->delivery->address))
                $customerAddress = $request->delivery->address;

            if(isset($request->products) && is_array($request->products)){

                foreach ($request->products as $product) {
                    info('$product:');
                    info(print_r($product,true));
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
                'name' => $request->localInfo->platform.' '.$request->shortCode,
                'ordering_platform' => $request->localInfo->platform,
                'customer' => $customer,
                'customer_identification' => $customerIdentification,
                'customer_address' => $customerAddress,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone,
                'app_deliverys' => true,
                'total' => $request->price->totalNet,
                'payment_type_id' => $request->payment->type === 'Efectivo' ? 1 : $store->id_tipo_pago_peya,
                'sale_type_id' => $store->id_tipo_venta_peya,
                'items' => json_encode($items,JSON_NUMERIC_CHECK|JSON_PRESERVE_ZERO_FRACTION),
                'body' => json_encode($request->all())
            ]));

            $createOrder = $createOrder->getData(true);

            if(!$createOrder['success']){

                //NOTIFICAR QUE NO SE PUDO CREAR LA ORDEN
                info('Error orderNotification: ');
                info($createOrder['msg']);

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

    }

}

<?php

namespace App\Http\Controllers;

use App\Jobs\RetryCancelOrderMp;
use App\Jobs\RetrySendOrderMp;
use App\Jobs\RetryUpdateOrderMp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MpFunctionController extends Controller
{
    public static function createMpOrder(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'id_branch_office' => 'required|numeric',
            'order_id' => 'required|string|min:3',
            'name' => 'required|string|min:3',
            'ordering_platform' => 'required|string|min:3',
            'total' => 'required|numeric|min:0',
            //'customer' => 'required_with:customer_identification',
            //'customer_address' => 'required_with:customer_identification',
            //'customer_email' => 'required_with:customer_identification',
            //'customer_phone' => 'required_with:customer_identification',
            'body' => 'nullable|json',
            'connect' => ['required','string','min:3',function($_, $value, $fail){

                if(!isset($value)){

                    $fail('La variable connect es obligatoria');

                }else{

                    $connection = base64_decode($value);

                    if(!DB::table('companies')->where('connect',$connection)->exists())
                        $fail('La tienda no existe');

                }

            }],
            'payment_type_id' => ['required','numeric',function($_, $value, $fail) use($request){

                $existsPaymentType =  DB::connection(base64_decode($request->connect))->table('tipo_pago')->where('id_tipo_pago',$value)->exists();

                if(!$existsPaymentType)
                    $fail('El tipo de pago ingresado no existe');

            }],
            'sale_type_id' => ['required','numeric',function($_, $value, $fail) use($request){

                $existsSaleType =  DB::connection(base64_decode($request->connect))->table('cliente')->where('id_cliente',$value)->exists();

                if(!$existsSaleType)
                    $fail('El tipo de venta ingresado no existe');

            }],
            'items' => ['required','json',function($_, $value, $fail) use($request){

                $items = json_decode($value);

                if(!is_array($items)){

                    $fail('El campo items debe ser un array de tipo json válido');

                }else if(!count($items)){

                    $fail('Debe ingresar al menos un item');

                }else{

                    foreach ($items as $item) {

                        if(!isset($item->id)){

                            $fail('El campo id es obligatorio');

                        }else if(!isset($item->name)){

                            $fail('El nombre del item es obligatorio');

                        }else if(!isset($item->type) || !in_array($item->type,['R','I'])){

                            $fail("El tipo de item {$item->name} debe ser R o I");

                        }else{

                            $connect = base64_decode($request->connect);

                            if($item->type == 'R'){

                                $existItem = DB::connection($connect)->table('receta')->where('id_receta',$item->id)->exists();

                            }else{

                                $existItem = DB::connection($connect)->table('item')->where('id_item',$item->id)->exists();

                            }

                            $existImp = DB::connection($connect)->table('impuesto')->where('valor',$item->tax)->exists();

                            if(!$existItem){

                                $fail("El id del item {$item->name} no existe");

                            }else if(!isset($item->tax) || !is_numeric($item->tax) || $item->tax < 0 || $item->tax > 100){

                                $fail("El campo tax del item {$item->name} debe ser un número entre 0 y 100");

                            }else if(!$existImp){

                                $fail("El impuesto {$item->tax} del item {$item->name} no existe");

                            }else if(!isset($item->quantity) || !is_numeric($item->quantity) || $item->quantity < 0){

                                $fail("El campo quantity del item {$item->name} debe ser un número positivo");

                            }else if(!isset($item->ingredient) || !is_numeric($item->ingredient) || !in_array($item->ingredient,[0,1])){

                                $fail("El campo ingredient del item {$item->name} debe ser un número entre 0 o 1");

                            }else if(!isset($item->sub_total_price) || !is_numeric($item->sub_total_price) || $item->sub_total_price < 0){

                                $fail("El campo sub_total_price del item {$item->name} debe ser un número positivo");

                            }else if(!isset($item->discount) || !is_numeric($item->discount) || $item->discount < 0){

                                $fail("El campo discount del item {$item->name} debe ser un número positivo, de no tener descuento debe ser 0");

                            }

                            if(isset($item->id_pcpp) && $item->id_pcpp!= ''){

                                $existImp = DB::connection($connect)->table('pos_configuracion_producto_pregunta')->where('id_pos_configuracion_producto_pregunta',$item->id_pcpp)->exists();

                                if(!$existImp)
                                    $fail("El valor {$item->id_pcpp} del campo id_pcpp del item {$item->name} no existe");

                            }

                        }

                    }

                }

            }],
        ],[
            'id_branch_office.required' => 'No se obtuvo el identificador de la tienda',
            'id_branch_office.numeric' => 'El identificador de la tienda debe ser un número',
            'order_id.required' => 'No se obtuvo el identificador de la orden',
            'order_id.string' => 'El identificador de la orden debe ser una cadena de carcaracteres',
            'order_id.min' => 'El identificador de la orden debe tener al menos 3 caracteres',
            'name.required' => 'No se obtuvo el nombre de la orden',
            'name.string' => 'El nombre de la orden debe ser una cadena de carcaracteres',
            'name.min' => 'El nombre de la orden debe tener al menos 3 caracteres',
            'ordering_platform.required' => 'No se obtuvo el nombre de la plataforma que origina la orden',
            'ordering_platform.string' => 'El nombre de la plataforma que origina la orden debe ser una cadena de carcaracteres',
            'ordering_platform.min' => 'El nombre de la plataforma que origina la orden debe tener al menos 3 caracteres',
            'customer.required' => 'No se obtuvo el nombre de la sucursal',
            //'customer_email.required_with' => 'Debe ingresar el correo electrónico del cliente',
            //'customer_phone.required_with' => 'Debe ingresar el teléfono del cliente',
            //'customer_address.required_with' => 'Debe ingresar la dirección del cliente',
            'total.required' => 'No se obtuvo el monto total de la orden',
            'total.numeric' => 'El monto total de la orden debe ser un numero',
            'total.min' => 'El monto total de la orden debe ser mayor o igual a 0',
            'connect.required' => 'No se obtuvo el nombre de la sucursal',
            'connect.required' => 'El acceso de la conexion es obligatorio',
            'connect.string' => 'El acceso de la conexion debe ser una cadena de carcaracteres',
            'connect.min' => 'El acceso de la conexion debe tener al menos 3 caracteres',
            'items.required' => 'No se obtuvieron los items de la orden',
            'items.json' => 'El campo items debe ser un array de tipo json válido',
            'sale_type_id.required' => 'No se obtuvo el tipo de venta',
            'sale_type_id.numeric' => 'El tipo de venta debe ser un numero',
            'payment_type_id.required' => 'No se obtuvo el tipo de pago',
            'payment_type_id.numeric' => 'El tipo de pago debe ser un numero',
            //'body.required' => 'El cuerpo de la orden es obligatorio',
            'body.json' => 'El cuerpo de la orden debe ser un json válido'
        ]);

        if (!$validate->fails()) {

            $conexion = base64_decode($request->connect);

            try{

                sleep(rand(2,15));

                if(self::pingMp($conexion)){

                    RetrySendOrderMp::dispatchNow($request->all(),$conexion);

                }else{

                    RetrySendOrderMp::dispatch($request->all(),$conexion)->onQueue('retry-send-order-mp');

                    $ip = config("database.connections.$conexion.host");

                    ContificoIntegrationController::sendMail([
                        'subject' => "Error en envío de pedido {$request->name} a la conexión {$conexion}",
                        'sucursal' => strtoupper($conexion),
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
                                    <p> No se pudo enviar el pedido {$request->name} a la conexion {$conexion}, no se pudo hacer ping a la ip {$ip} desde el sistema Integrador, el proceso se reintentará en 2 minutos con un job</p>
                                </div>
                            </body>
                        </html>"
                    ]);

                }

                return response()->json([
                    'success' => true,
                    'msg' => 'Se ha creado el pedido con éxito'
                ],200);

            }catch(\Exception $e){

                info('Error createMpAccount: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile());

                ContificoIntegrationController::sendMail([
                    'subject' => "Error en envío de pedido {$request->name} a la conexión {$conexion}",
                    'sucursal' => strtoupper($conexion),
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
                                <p> Error createMpAccount: {$e->getMessage()} {$e->getLine()} {$e->getFile()}</p>
                            </div>
                        </body>
                    </html>"
                ]);

                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage().' '.$e->getLine().' '.$e->getFile()
                ],500);

            }

        } else {

            return response()->json([
                'success' => false,
                "msg" => $validate->errors()->all(),
            ], 422);

        }

    }

    public static function updateMpOrderAppDelivery(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'ordering_platform' => 'required|string|min:3',
            'body' => 'nullable|json',
            'status' => 'required|string|min:3',
            'tiempo_preparacion' => 'required|numeric|min:0',
            'connect' => ['required','string','min:3',function($_, $value, $fail){

                if(!isset($value)){

                    $fail('La variable connect es obligatoria');

                }else{

                    $connection = base64_decode($value);

                    if(!DB::table('companies')->where('connect',$connection)->exists())
                        $fail('La tienda no existe');

                }

            }],
            'order_id' => ['required','string','min:3',function($_, $value, $fail) use($request){

                if(!isset($value)){

                    $fail('El parámetro order_id es obligatorio');

                }else{

                    $connection = base64_decode($request->connect);

                    $existsOrder = DB::connection($connection)->table('precuenta as p')
                    ->join('precuenta_app_delivery as app','p.id_precuenta','app.id_precuenta')
                    ->where('p.default_name',$value)->exists();

                    if(!$existsOrder)
                        $fail('No existe una oredn con el identificador '.$value);

                }

            }],
        ],[
            'id_branch_office.required' => 'No se obtuvo el identificador de la tienda',
            'id_branch_office.numeric' => 'El identificador de la tienda debe ser un número',
            'order_id.required' => 'No se obtuvo el identificador de la orden',
            'order_id.string' => 'El identificador de la orden debe ser una cadena de carcaracteres',
            'order_id.min' => 'El identificador de la orden debe tener al menos 3 caracteres',
            'ordering_platform.required' => 'No se obtuvo el nombre de la plataforma que origina la orden',
            'ordering_platform.string' => 'El nombre de la plataforma que origina la orden debe ser una cadena de carcaracteres',
            'ordering_platform.min' => 'El nombre de la plataforma que origina la orden debe tener al menos 3 caracteres',
            'connect.required' => 'No se obtuvo el nombre de la sucursal',
            'connect.required' => 'El acceso de la conexion es obligatorio',
            'connect.string' => 'El acceso de la conexion debe ser una cadena de carcaracteres',
            'connect.min' => 'El acceso de la conexion debe tener al menos 3 caracteres',
            'status.required' => 'No se obtuvo el estado de la orden',
            'status.string' => 'El estado de la orden debe ser una cadena de carcaracteres',
            'status.min' => 'El estado de la orden debe tener al menos 3 caracteres',
            'body.json' => 'El cuerpo de la orden debe ser un json válido',
            'tiempo_preparacion.required' => 'No se obtuvo el tiempo de preparación de la orden',
            'tiempo_preparacion.numeric' => 'El tiempo de preparación de la orden debe ser un número',
            'tiempo_preparacion.min' => 'El tiempo (en segundos) de preparación de la orden debe ser mayor o igual a 1'
        ]);

        if (!$validate->fails()) {

            $conexion = base64_decode($request->connect);

            try{

                if(self::pingMp($conexion)){

                    RetryUpdateOrderMp::dispatchNow($request->all(),$conexion);

                }else{

                    RetryUpdateOrderMp::dispatch($request->all(),$conexion)->onQueue('retry-update-order-mp');

                    $ip = config("database.connections.$conexion.host");

                    ContificoIntegrationController::sendMail([
                        'subject' => "Error al actualizar la orden {$request->order_id} en la conexión {$conexion}",
                        'sucursal' => strtoupper($conexion),
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
                                    <p> No se pudo actualizar la orden {$request->order_id} en la conexion {$conexion}, no se pudo hacer ping a la ip {$ip} desde el sistema Integrador, el proceso se reintentará en 2 minutos con un job</p>
                                </div>
                            </body>
                        </html>"
                    ]);

                }

            }catch(\Exception $e){

                info('Error updateMpAccount: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile());

                ContificoIntegrationController::sendMail([
                    'subject' => "Error al actualizar la orden {$request->order_id} en la conexión {$conexion}",
                    'sucursal' => strtoupper($conexion),
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
                                <p> Error updateMpAccount: {$e->getMessage()} {$e->getLine()} {$e->getFile()}</p>
                            </div>
                        </body>
                    </html>"
                ]);

                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage().' '.$e->getLine().' '.$e->getFile()
                ],500);

            }

            return response()->json([
                'success' => true,
                'msg' => 'Se ha actualizado el pedido con éxito'
            ],200);

        } else {

            return response()->json([
                'success' => false,
                "msg" => $validate->errors()->all(),
            ], 422);

        }

    }

    public static function deleteMpOrderAppDelivery(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'order_id' => 'required|string|min:3',
            'connect' => ['required','string','min:3',function($_, $value, $fail){

                if(!isset($value)){

                    $fail('La variable connect es obligatoria');

                }else{

                    $connection = base64_decode($value);

                    if(!DB::table('companies')->where('connect',$connection)->exists())
                        $fail('La tienda no existe');

                }

            }],
        ],[
            'order_id.required' => 'No se obtuvo el identificador de la orden',
            'order_id.string' => 'El identificador de la orden debe ser una cadena de carcaracteres',
            'order_id.min' => 'El identificador de la orden debe tener al menos 3 caracteres',
            'connect.required' => 'No se obtuvo el nombre de la sucursal',
            'connect.required' => 'El acceso de la conexion es obligatorio',
            'connect.string' => 'El acceso de la conexion debe ser una cadena de carcaracteres',
            'connect.min' => 'El acceso de la conexion debe tener al menos 3 caracteres',
        ]);

        if (!$validate->fails()) {

            $connection = DB::connection(base64_decode($request->connect));

            try {

                $connection->beginTransaction();

                $precuenta =  $connection->table('precuenta')->where('default_name',$request->order_id)->first();

                $connection->table('detalle_precuenta')->where('id_precuenta',$precuenta->id_precuenta)->delete();
                $connection->table('precuenta_base_impuesto')->where('id_precuenta',$precuenta->id_precuenta)->delete();
                $connection->table('precuenta_app_delivery')->where('cuerpo->order->id',$request->order_id)->delete();
                $connection->table('precuenta')->where('default_name',$request->order_id)->delete();

                $connection->commit();

                return response()->json([
                    'success' => true,
                    'msg' => 'Se eliminado el pedido con éxito'
                ],200);

            } catch (\Exception $e) {

                info('Error deleteMpOrderAppDelivery: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile().' '.$e->getTraceAsString());

                $connection->rollBack();

                return response()->json([
                    'success' => false,
                    'msg' => $e->getLine().' '.$e->getMessage().' '.$e->getLine()
                ],500);

            }

        } else {

            return response()->json([
                'success' => false,
                "msg" => $validate->errors()->all(),
            ], 422);

        }

    }

    public static function cancelMpOrderAppDelivery(Request $request)
    {
        $validate = Validator::make($request->all(), [
            'order_id' => 'required|string|min:3',
            'connect' => ['required','string','min:3',function($_, $value, $fail){

                if(!isset($value)){

                    $fail('La variable connect es obligatoria');

                }else{

                    $connection = base64_decode($value);

                    if(!DB::table('companies')->where('connect',$connection)->exists())
                        $fail('La tienda no existe');

                }

            }],
        ],[
            'order_id.required' => 'No se obtuvo el identificador de la orden',
            'order_id.string' => 'El identificador de la orden debe ser una cadena de carcaracteres',
            'order_id.min' => 'El identificador de la orden debe tener al menos 3 caracteres',
            'connect.required' => 'No se obtuvo el nombre de la sucursal',
            'connect.required' => 'El acceso de la conexion es obligatorio',
            'connect.string' => 'El acceso de la conexion debe ser una cadena de carcaracteres',
            'connect.min' => 'El acceso de la conexion debe tener al menos 3 caracteres',
        ]);

        if (!$validate->fails()) {

            $conexion = base64_decode($request->connect);

            try{

                if(self::pingMp($conexion)){

                    RetryCancelOrderMp::dispatchNow($request->all(),$conexion);

                }else{

                    RetryCancelOrderMp::dispatch($request->all(),$conexion)->onQueue('retry-cancel-order-mp');

                    $ip = config("database.connections.$conexion.host");

                    ContificoIntegrationController::sendMail([
                        'subject' => "Error al cancelar de pedido {$request->order_id} en la conexión {$conexion}",
                        'sucursal' => strtoupper($conexion),
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
                                    <p> No se pudo cancelar el pedido {$request->order_id} en la conexion {$conexion}, no se pudo hacer ping a la ip {$ip} desde el sistema Integrador, el proceso se reintentará en 2 minutos con un job</p>
                                </div>
                            </body>
                        </html>"
                    ]);

                }

            }catch(\Exception $e){

                info('Error cancelMpAccount: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile());

                ContificoIntegrationController::sendMail([
                    'subject' => "Error al cancelar la orden {$request->order_id} en la conexión {$conexion}",
                    'sucursal' => strtoupper($conexion),
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
                                <p> Error cancelMpAccount: {$e->getMessage()} {$e->getLine()} {$e->getFile()}</p>
                            </div>
                        </body>
                    </html>"
                ]);

                return response()->json([
                    'success' => false,
                    'msg' => $e->getMessage().' '.$e->getLine().' '.$e->getFile()
                ],500);

            }

            return response()->json([
                'success' => true,
                'msg' => 'Se eliminado el pedido con éxito'
            ],200);

        } else {

            return response()->json([
                'success' => false,
                "msg" => $validate->errors()->all(),
            ], 422);

        }

    }

}

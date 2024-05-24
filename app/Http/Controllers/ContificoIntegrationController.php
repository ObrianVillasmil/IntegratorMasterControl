<?php

namespace App\Http\Controllers;

use App\Mail\SendInvoicesContifico;
use App\Models\Company;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ContificoIntegrationController extends Controller
{
    static function sendInvoices(Request $request)
    {
        $company = Company::where('name',$request->company)->first();

        $header = [
            'Content-Type' => 'application/json',
            'Authorization: '.$company->token
        ];

        $connection = DB::connection($company->connect);

        $idBranchOffice = $connection->table('empresa as e')->join('sucursal as s',function($j) {

            $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

        })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

        $ventas = $connection->table('venta')
        ->where('estado',true)
        ->where('venta_confirmada_externo',false)
        ->whereIn('id_sucursal',$idBranchOffice)
        ->where(DB::raw("fecha::date"),'>=','2024-05-24')
        ->whereNull('secuencial_nota_credito')
        ->whereNotNull('secuencial')
        ->get();

        try {

            foreach ($ventas as $v) {

                $cedula = '';
                $ruc = '';

                if(strlen($v->identificacion_comprador) == 10){

                    $cedula = $v->identificacion_comprador;
                    $ruc = $cedula.'001';

                }else{

                    $cedula = substr($v->identificacion_comprador,0,10);
                    $ruc = $v->identificacion_comprador;

                }

                $base0 = $connection->table('venta_base_impuesto')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->where('id_impuesto',1)->sum('valor_base');

                $baseMayor0 = $connection->table('venta_base_impuesto')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->whereNotIn('id_impuesto',[1,2,3])->sum('valor_base');

                $iva =  $connection->table('venta_base_impuesto')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->sum('valor_impuesto');

                $data = [
                    "pos" => $company->token2,
                    "fecha_emision" =>  Carbon::parse($v->fecha)->format('d/m/Y'),
                    "tipo_documento" => "FAC",
                    "documento" => substr($v->secuencial,24,3)."-".substr($v->secuencial,27,3)."-".substr($v->secuencial,30,9),
                    "estado" => "P",
                    "autorizacion" => $v->secuencial,
                    "caja_id" => null,
                    "cliente" => [
                        "ruc"=>  $ruc,
                        "cedula"=>  $cedula,
                        "razon_social"=> $v->nombre_comprador,
                        "telefonos"=> $v->telefono_comprador,
                        "direccion"=> $v->direccion_comprador,
                        "tipo"=> "N",
                        "email"=> $v->correo_comprador
                    ],
                    "descripcion" => "FACTURA ".(int)substr($v->secuencial,30,9),
                    "subtotal_0" => number_format($base0,2,'.',''),
                    "subtotal_12" => number_format($baseMayor0,2,'.',''),
                    "iva" => number_format($iva,2,'.',''),
                    "ice" =>0.00,
                    "servicio" => number_format($v->servicio,2,'.',''),
                    "total" => number_format($v->total_a_pagar,2,'.',''),
                    "detalles"=> [],
                    "cobros"=>[]
                ];

                $detVenta = $connection->table('detalle_venta')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)->get();

                foreach ($detVenta as $det) {

                    $baseCero = 0;
                    $baseGravable = 0;
                    $base = ($det->precio*$det->cantidad)-$det->monto_descuento;

                    if($det->impuesto == 0){

                        $baseCero = $base;

                    }else{

                        $baseGravable = $base;

                    }

                    $pcp = $connection->table('pos_configuracion_producto')
                    ->where('tabla',($det->tipo_producto == 'R' ? 'receta' : 'item'))
                    ->where('id_producto',$det->id_producto)->first();

                    $data['detalles'][] = [
                        "producto_id" => $pcp->id_externo,
                        "cantidad" => $det->cantidad,
                        "precio" => $det->precio,
                        "porcentaje_iva" => $det->impuesto,
                        "porcentaje_descuento" => 0.00,
                        "base_cero" => $baseCero,
                        "base_gravable" => $baseGravable,
                        "base_no_gravable" => 0.00
                    ];

                }

                $pagosVenta = $connection->table('venta_tipo_pago')
                ->where('id_venta', $v->id_venta)
                ->where('id_sucursal', $v->id_sucursal)->get();

                foreach ($pagosVenta as $pago) {

                    $formaCobro = 'EF';

                    if($pago->id_tipo_pago == 6 || $pago->id_tipo_pago == 2){

                        $formaCobro = 'TRA';

                    }else if($pago->id_tipo_pago == 3){

                        $formaCobro = 'TC';

                    }

                    $data['cobros'][] = [
                        "forma_cobro" => $formaCobro,
                        "monto" => $pago->monto,
                        "cuenta_bancaria_id" => $company->bank,
                        "numero_comprobante" => $pago->referencia,
                        "tipo_ping" => "D"
                    ];

                }

                $jsonVentas = json_encode($data);

                $curlClient = curl_init();

                curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
                curl_setopt($curlClient, CURLOPT_URL, env('ENVIAR_VENTA_AUTORIZADA_CONTIFICO'));
                curl_setopt($curlClient, CURLOPT_POST, true);
                curl_setopt($curlClient, CURLOPT_POSTFIELDS, $jsonVentas);
                curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 30);
                $response = curl_exec($curlClient);

                $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

                curl_close($curlClient);

                $response = json_decode($response);

                if($response!=null){

                    if($codigoHttp == 201){
                       
                        $connection->table('venta')
                        ->where('id_venta',$v->id_venta)
                        ->where('id_sucursal',$v->id_sucursal)
                        ->update([
                            'id_externo' => $response->id,
                            'venta_confirmada_externo' => true
                        ]);

                    }else{

                        $html = "<div>Ha ocurrido un inconveniente al momento de enviar la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.=" <div><b>Error:</b> ".$response->mensaje." </div>" ;
                        $html.= " <div><b>Codigo de error contifico:</b> ".$response->cod_error."</div>";
                        throw new Exception($html);

                    }

                    sleep(2);

                }else{

                    throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la venta ".$v->secuencial." de la empresa ".$request->company);

                }

                dump('$codigoHttp: '.$codigoHttp);
                dump('response');
                dump($response);

            }

            if(count($ventas))
                Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico("Se han procesado ".count($ventas)." venta en el contifico de la empresa ".$request->company));

        } catch (\Exception $e) {

            Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico($e->getMessage(),false));

        }

    }

}

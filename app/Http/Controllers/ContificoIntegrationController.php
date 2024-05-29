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
        try {

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
            ->where(DB::raw("fecha::date"),'>=','2024-03-25')
            ->whereNull('secuencial_nota_credito')
            ->whereNotNull('secuencial')->get();

            foreach ($ventas as $v) {

                $productContifico = env('PRODUCTO_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal);

                if($productContifico == null || $productContifico == '')
                    throw new Exception("No se obtuvo el producto Contifico al momento de enviar la venta ".$v->secuencial." de la empresa ".$company->connect." para la tienda ".$v->id_sucursal);

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

                $prod = $connection->table('detalle_venta')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->selectRaw("SUM(precio*cantidad) AS precio")
                ->select('impuesto')->first();

                $prod->precio -= ($v->descuento1 + $v->descuento2);

                $dataFactura = [
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
                    "detalles"=> [
                        "producto_id" => $productContifico,
                        "cantidad" => 1,
                        "precio" => number_format($prod->precio,2,'.',''),
                        "porcentaje_iva" => $prod->impuesto,
                        "porcentaje_descuento" => 0.00,
                        "base_cero" => $base0,
                        "base_gravable" => $baseMayor0,
                        "base_no_gravable" => 0.00
                    ]
                ];

                //SE CREA LA FACTURA
                $resFact = self::curlStoreTransaction($dataFactura,$header,env('CREAR_FACTURA_CONTIFICO'));

                if($resFact['response'] != null){

                    if($resFact['http'] == 201){

                        $connection->table('venta')
                        ->where('id_venta',$v->id_venta)
                        ->where('id_sucursal',$v->id_sucursal)
                        ->update([
                            'id_externo' => $resFact['response']->id,
                            'venta_confirmada_externo' => true
                        ]);

                        sleep(2);

                    }else{

                        $html ="<div>Ha ocurrido un inconveniente al momento de enviar la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.="<div><b>Error:</b> ".$resFact['response']->mensaje." </div>" ;
                        $html.="<div><b>Codigo de error contifico:</b> ".$resFact['response']->cod_error."</div>";
                        throw new Exception($html);

                    }

                }else{

                    throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la venta ".$v->secuencial." de la empresa ".$request->company);

                }
                //FIN SE CREA LA FACTURA

                //SE CREAN LOS ASIENTOS DE LA FACTURA
                $dataAsiento = [
                    "fecha" => Carbon::parse($v->fecha)->format('d/m/Y'),
                    "glosa" => "FACTURA ".$dataFactura['documento'],
                    "gasto_no_deducible"=> 0,
                    "prefijo"=> "ASI",
                    "detalles" => [
                        [
                            "cuenta_id" => env('CUENTA_CLIENTES_VENTAS_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal),
                            "valor" => $dataFactura['total'],
                            "tipo"=> "D",
                        ],
                        [
                            "cuenta_id" => env('CUENTA_IVA_VENTAS_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal),
                            "valor" => $dataFactura['iva'],
                            "tipo"=> "H",
                        ],
                        [
                            "cuenta_id" => env('CUENTA_PRODUCTO_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal),
                            "valor" => $dataFactura['detalles'][0]['precio'],
                            "tipo"=> "H",
                            "centro_costo_id" => env('CENTRO_COSTO_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal)
                        ]
                    ]
                ];

                if($v->servicio > 0){

                    $detAsiento['detalles'][] = [
                        "cuenta_id" => env('CUENTA_SERVICO_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal),
                        "valor" => $dataFactura['servicio'],
                        "tipo"=> "H",
                    ];

                }

                if($v->propina > 0){

                    $detAsiento['detalles'][] = [
                        "cuenta_id" => env('CUENTA_PROPINA_CONTIFICO_'.strtoupper($company->connect).'_'.$v->id_sucursal),
                        "valor" => number_format($v->propina,2,'.',''),
                        "tipo"=> "H",
                    ];

                }

                $resAsientoFact = self::curlStoreTransaction($dataAsiento,$header,env('CREAR_ASIENTO_CONTIFICO'));

                if($resAsientoFact['response'] == null){

                    if($resAsientoFact['http'] != 201){

                        $html ="<div>Ha ocurrido un inconveniente al momento de crear el asiento de la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.="<div><b>Error:</b> ".$resAsientoFact['response']->mensaje." </div>" ;
                        $html.="<div><b>Codigo de error contifico:</b> ".$resAsientoFact['response']->cod_error."</div>";
                        throw new Exception($html);

                    }else{

                        sleep(2);

                    }

                }
                //FIN SE CREAN LOS ASIENTOS DE LA FACTURA

                //CREAR Y CRUZAR COBROS DE LA FACTURA
                $datosCobros = [];

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

                    $datosCobros[] = [
                        "forma_cobro" => $formaCobro,
                        "monto" => $pago->monto,
                        "cuenta_bancaria_id" => $company->bank,
                        "numero_comprobante" => $pago->referencia,
                        "tipo_ping" => "D"
                    ];

                }

                $resPago = self::curlStoreTransaction($dataFactura,$header,env('CREAR_FACTURA_CONTIFICO').$resFact['response']->id."/cobro/");

                if($resPago['response'] == null){

                    if($resPago['http'] != 201){

                        $html ="<div>Ha ocurrido un inconveniente al momento de crear el pago de la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.="<div><b>Error:</b> ".$resPago['response']->mensaje." </div>" ;
                        $html.="<div><b>Codigo de error contifico:</b> ".$resPago['response']->cod_error."</div>";
                        throw new Exception($html);

                    }else{

                        sleep(2);

                    }

                }
                //FIN CREAR Y CRUZAR COBROS DE LA FACTURA

                

                /* dump('$codigoHttp: '.$response['http']);
                dump('response');
                dump($response); */

            }

            if(count($ventas))
                Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico("Se han procesado ".count($ventas)." venta en el contifico de la empresa ".$request->company));


            /// NOTAS DE CREDITO ///
            /* $ventasNc = $connection->table('venta')
            ->where('estado',false)
            ->where('cn_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereNotNull(['secuencial_nota_credito','json_cn','id_externo'])
            ->where("CAST(json_cn->>'date_doc' AS DATE)",'>=','2024-05-24')->get();

            foreach ($ventasNc as $vnc) {

                $cn = json_decode($vnc->json_cn);

                $cedula = '';
                $ruc = '';

                if(strlen((string)$cn->buyer_identification) == 10){

                    $cedula = (string)$cn->buyer_identification;
                    $ruc = $cedula.'001';

                }else{

                    $cedula = substr((string)$cn->buyer_identification,0,10);
                    $ruc = (string)$cn->buyer_identification;

                }

                $base0 = 0;
                $baseMayor0 = 0;

                foreach ($cn->details as $detCn) {

                    foreach ($detCn->credit_note_item_tax as $tax) {

                        if($tax->tariff == 0){

                            $base0+= (float)$tax->tax_base;

                        }else{

                            $baseMayor0+= (float)$tax->tax_base;

                        }

                    }

                }

                $data = [
                    "pos" => $company->token2,
                    "fecha_emision" =>  Carbon::parse((string)$cn->date_doc)->format('d/m/Y'),
                    "tipo_documento" => "NCT",
                    "documento_relacionado_id" => $vnc->id_externo,
                    "tipo_registro" => "CLI",
                    "documento" => substr((string)$cn->access_key,24,3)."-".substr((string)$cn->access_key,27,3)."-".substr((string)$cn->access_key,30,9),
                    "autorizacion" => (string)$cn->access_key,
                    "estado" => "P",
                    "cliente" => [
                        "ruc"=>  $ruc,
                        "cedula"=>  $cedula,
                        "razon_social"=> (string)$cn->buyer_business_name,
                        "telefonos"=> $v->telefono_comprador,
                        "direccion"=> $v->direccion_comprador,
                        "tipo"=> "N",
                        "email"=> (string)$cn->emails
                    ],
                    "descripcion" => "NOTA DE CRÉDITO ".(int)substr((string)$cn->access_key,30,9),
                    "subtotal_0" => number_format($base0,2,'.',''),
                    "subtotal_12" => number_format($baseMayor0,2,'.',''),
                    "iva" => number_format((float)$cn->modified_value - (float)$cn->total_without_tax,2,'.',''),
                    "total" => number_format((float)$cn->modified_value,2,'.',''),
                    "detalles"=> [],
                ];

                foreach ($cn->details as $detCn){

                    //VERIFICAR LO DEL PRODUCTO DEL SERVICIO

                    $idProduto = (int)substr(explode('-',$detCn->main_code)[1],-6);
                    $tipoProducto = substr(explode('-',$detCn->main_code)[1],0,1);

                    $pcp = $connection->table('pos_configuracion_producto')
                    ->where('tabla',($tipoProducto == 'R' ? 'receta' : 'item'))
                    ->where('id_producto',$idProduto)->first();

                    $data['detalles'][] = [
                        "producto_id" => $pcp->id_externo,
                        "cantidad" => number_format((float)$cn->quantity,2,'.',''),
                        "precio" => number_format((float)$cn->unit_price,2,'.',''),
                        "porcentaje_iva" => $detCn[0]->tariff,
                        "porcentaje_descuento" => 0.00,
                        "base_cero" => $baseCero,
                        "base_gravable" => $baseGravable,
                        "base_no_gravable" => 0.00
                    ];

                }

                $response = self::curlStoreTransaction($data,$header);

                if($response['response'] != null){

                    if($response['http'] == 201){

                        $connection->table('venta')
                        ->where('id_venta',$vnc->id_venta)
                        ->where('id_sucursal',$vnc->id_sucursal)
                        ->update(['cn_confirmada_externo' => true]);

                    }else{

                        $html = "<div>Ha ocurrido un inconveniente al momento de enviar la nota de crédito <b>".$vnc->access_key."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.=" <div><b>Error:</b> ".$response['response']->mensaje." </div>" ;
                        $html.= " <div><b>Codigo de error contifico:</b> ".$response['response']->cod_error."</div>";
                        throw new Exception($html);

                    }

                    sleep(2);

                }else{

                    throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la nota de crédito <b>".$vnc->access_key." de la empresa ".$request->company);

                }

                dump('$codigoHttp: '.$response['http']);
                dump('response');
                dump($response);

            }

            if(count($ventasNc))
                Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico("Se han procesado ".count($ventasNc)." notas de crédito en el contifico de la empresa ".$request->company)); */

        } catch (\Exception $e) {

            Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico($e->getMessage(),false));

        }

    }

    static function curlStoreTransaction($data,$header,$url) {

        $jsonVentas = json_encode($data);

        $curlClient = curl_init();

        curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curlClient, CURLOPT_URL, $url);
        curl_setopt($curlClient, CURLOPT_POST, true);
        curl_setopt($curlClient, CURLOPT_POSTFIELDS, $jsonVentas);
        curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 30);
        $response = curl_exec($curlClient);

        $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

        curl_close($curlClient);

        $response = json_decode($response);

        return [
            'http' =>$codigoHttp,
            'response' => $response
        ];

    }

}

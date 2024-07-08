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

            $accionesFallidas = [];

            $accionesCompletadas = [];

            $company = Company::where('name',$request->company)->first();

            $header = [
                'Content-Type' => 'application/json',
                'Authorization: '.$company->token
            ];

            $connection = DB::connection($company->connect);

            $idBranchOffice = $connection->table('empresa as e')
            ->join('sucursal as s',function($j) {

                $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

            })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

            $ventas = $connection->table('venta')
            ->where('estado',true)
            ->where('venta_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->where(DB::raw("fecha::date"),'>=','2024-06-04')
            ->whereNull(['secuencial_nota_credito','id_externo'])
            ->whereNotNull('secuencial')->get();
           // dd($ventas);
            foreach ($ventas as $v) {

                $productContifico = env('PRODUCTO_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal);

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
                ->where('id_impuesto',1)->sum('sub_total');

                $baseMayor0 = $connection->table('venta_base_impuesto')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->whereNotIn('id_impuesto',[1,2,3])->sum('sub_total');

                $iva =  $connection->table('venta_base_impuesto')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->sum('valor_impuesto');

                $prod = $connection->table('detalle_venta')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)
                ->select('impuesto')->first();

                $dataFactura = [
                    "pos" => env('API_POS_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                    "fecha_emision" =>  Carbon::parse($v->fecha)->format('d/m/Y'),
                    "tipo_documento" => "FAC",
                    "documento" => substr($v->secuencial,24,3)."-".substr($v->secuencial,27,3)."-".substr($v->secuencial,30,9),
                    "estado" => "P",
                    "autorizacion" => $v->secuencial,
                    "caja_id" => null,
                    "descripcion" => "FACTURA ".(substr($v->secuencial,24,3)."-".substr($v->secuencial,27,3)."-".substr($v->secuencial,30,9)),
                    "subtotal_0" => number_format($base0,2,'.',''),
                    "subtotal_12" => number_format($baseMayor0,2,'.',''),
                    "iva" => number_format($iva,2,'.',''),
                    "ice" =>0.00,
                    "servicio" => number_format($v->servicio,2,'.',''),
                    "total" => number_format($v->total_a_pagar-$v->propina,2,'.',''),
                    "cliente" => [
                        "ruc"=>  $ruc,
                        "cedula"=>  $cedula,
                        "razon_social"=> $v->nombre_comprador,
                        "telefonos"=> $v->telefono_comprador,
                        "direccion"=> $v->direccion_comprador,
                        "tipo"=> "N",
                        "email"=> $v->correo_comprador
                    ],
                    "detalles"=> [
                        [
                            "producto_id" => $productContifico,
                            "cantidad" => 1,
                            "precio" => $baseMayor0,
                            "porcentaje_iva" => $prod->impuesto,
                            "porcentaje_descuento" => 0.00,
                            "base_cero" => $base0,
                            "base_gravable" => $baseMayor0,
                            "base_no_gravable" => 0.00
                        ]
                    ]
                ];

                if($v->propina > 0){

                    $dataFactura["adicional1"] = 'Propina USD'.$v->propina;

                    $pagosVenta = $connection->table('venta_tipo_pago as vtp')
                    ->join('tipo_pago as tp','tp.id_tipo_pago = vtp.id_tipo_pago')
                    ->where('id_venta', $v->id_venta)
                    ->where('id_sucursal', $v->id_sucursal)
                    ->select('vtp.*','tp.nombre')->get();

                    $cobros = [];

                    foreach ($pagosVenta as $pago)
                        $cobros[]= ($pago->nombre.': USD'.$pago->monto);

                    $dataFactura["adicional2"] = implode(' ,',$cobros);

                }

                //dd($dataFactura);
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

                        //SE CREAN LOS ASIENTOS DE LA FACTURA
                        $dataAsientoFact = [
                            "fecha" => Carbon::parse($v->fecha)->format('d/m/Y'),
                            "glosa" => "FACTURA ".$dataFactura['documento'],
                            "gasto_no_deducible"=> 0,
                            "prefijo"=> "ASI",
                            "detalles" => [
                                [
                                    "cuenta_id" => env('CUENTA_CLIENTES_VENTAS_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                    "valor" => $v->total_a_pagar,
                                    "tipo"=> "D",
                                ],
                                [
                                    "cuenta_id" => env('CUENTA_IVA_VENTAS_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                    "valor" => $dataFactura['iva'],
                                    "tipo"=> "H",
                                ],
                                [
                                    "cuenta_id" => env('CUENTA_PRODUCTO_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                    "valor" => $dataFactura['detalles'][0]['precio'],
                                    "tipo"=> "H",
                                    "centro_costo_id" => env('CENTRO_COSTO_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal)
                                ]
                            ]
                        ];

                        if($v->servicio > 0){

                            $dataAsientoFact['detalles'][] = [
                                "cuenta_id" => env('CUENTA_SERVICO_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                "valor" => $dataFactura['servicio'],
                                "tipo"=> "H",
                            ];

                        }

                        $resAsientoFact = self::curlStoreTransaction($dataAsientoFact,$header,env('CREAR_ASIENTO_CONTIFICO'));

                        if($resAsientoFact['response'] != null){

                            if($resAsientoFact['http'] == 201){

                                sleep(2);

                                //CREAR Y CRUZAR COBROS DE LA FACTURA
                                $datosCobros = [];

                                if($v->propina == 0){

                                    $pagosVenta = $connection->table('venta_tipo_pago')
                                    ->where('id_venta', $v->id_venta)
                                    ->where('id_sucursal', $v->id_sucursal)->get();

                                    //DATOS PARA EL ASIENTO CONTABLE DE LOS COBROS
                                    $dataAsientoCobro = [
                                        "fecha" => Carbon::parse($v->fecha)->format('d/m/Y'),
                                        "glosa" => "COBRO FACTURA VENTA ".$dataFactura['documento'],
                                        "gasto_no_deducible"=> 0,
                                        "prefijo"=> "ASI",
                                        "detalles" => [
                                            [
                                                "cuenta_id" => env('CUENTA_COBRO_PARTIDADOBLE_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                                "valor" => $dataFactura['total'],
                                                "tipo"=> "H",
                                            ],
                                        ]
                                    ];

                                    foreach ($pagosVenta as $pago) {

                                        if(!in_array($pago->id_tipo_pago, [4,5])){

                                            if($pago->id_tipo_pago == 3){

                                                $formaCobro = 'TC';

                                                $dataAsientoCobro['detalles'][] =[
                                                    "cuenta_id" => env('CUENTA_COBRO_TARJETA_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                                    "valor" => $pago->monto,
                                                    "tipo"=> "D"
                                                ];

                                            }else{

                                                $formaCobro = 'EF';

                                                $dataAsientoCobro['detalles'][] =[
                                                    "cuenta_id" => env('CUENTA_COBRO_EFECTIVO_CONTIFICO_'.strtoupper($company->name).'_'.$v->id_sucursal),
                                                    "valor" => $pago->monto,
                                                    "tipo"=> "D"
                                                ];

                                            }

                                            $datosCobros[] = [
                                                "forma_cobro" => $formaCobro,
                                                "monto" => $pago->monto,
                                                "cuenta_bancaria_id" => $company->bank,
                                                "numero_comprobante" => $pago->referencia,
                                                "tipo_ping" => "D"
                                            ];

                                        }

                                    }
                                    //FIN DATOS PARA EL ASIENTO CONTABLE DE LOS COBROS

                                }

                                //CREAR Y CRUZAR COBROS DE LA FACTURA
                                if(count($datosCobros)){

                                    foreach ($datosCobros as  $cobro) {

                                        $resCobro = self::curlStoreTransaction($cobro,$header,(env('CREAR_FACTURA_CONTIFICO').$resFact['response']->id."/cobro/"));

                                        if($resCobro['response'] != null){

                                            if($resCobro['http'] == 201){

                                                sleep(2);

                                                // CREAR LOS ASIENTOS DEL COBRO
                                                $resAsientoCobro = self::curlStoreTransaction($dataAsientoCobro,$header,env('CREAR_ASIENTO_CONTIFICO'));

                                                if($resAsientoCobro['response'] != null){

                                                    if($resAsientoCobro['http'] == 201){

                                                        $accionesCompletadas[] = 'Factura '.$v->secuencial.' enviada al contifico';
                                                        sleep(2);

                                                    }else{

                                                        $html ="<div>Ha ocurrido un inconveniente al momento de crear el asiento de los pagos de la factura <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> en contifico </div>";
                                                        $html.="<div><b>Error:</b> ".$resAsientoCobro['response']->mensaje." </div>" ;
                                                        $html.="<div><b>Codigo de error contifico:</b> ".$resAsientoCobro['response']->cod_error."</div>";
                                                        $html.="<div><b>DATA:</b> ".json_encode($dataAsientoCobro)."</div>";
                                                        $accionesFallidas[] = $html;
                                                        //throw new Exception($html);

                                                    }

                                                }else{

                                                    $accionesFallidas[] = "No se obtuvo respuesta de Contifico al momento de crear el asiento de los cobros de la venta ".$v->secuencial." de la empresa ".$request->company;
                                                    //throw new Exception("No se obtuvo respuesta de Contifico al momento de crear el asiento de los cobros de la venta ".$v->secuencial." de la empresa ".$request->company);

                                                }

                                                // FIN CREAR LOS ASIENTOS DEL COBRO

                                            }else{

                                                $html ="<div>Ha ocurrido un inconveniente al momento de crear el pago de la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                                                $html.="<div><b>Error:</b> ".$resCobro['response']->mensaje." </div>" ;
                                                $html.="<div><b>Codigo de error contifico:</b> ".$resCobro['response']->cod_error."</div>";
                                                $accionesFallidas[] = $html;
                                                //throw new Exception($html);

                                            }

                                        }else{

                                            $accionesFallidas[] = "No se obtuvo respuesta de Contifico al momento de crear los cobros de la venta ".$v->secuencial." de la empresa ".$request->company;
                                            //throw new Exception("No se obtuvo respuesta de Contifico al momento de crear los cobros de la venta ".$v->secuencial." de la empresa ".$request->company);

                                        }

                                    }

                                }else{

                                    $accionesCompletadas[] = 'Factura '.$v->secuencial.' enviada al contifico';

                                }

                                //FIN CREAR Y CRUZAR COBROS DE LA FACTURA

                            }else{

                                $html ="<div>Ha ocurrido un inconveniente al momento de crear el asiento de la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                                $html.="<div><b>Error:</b> ".$resAsientoFact['response']->mensaje." </div>" ;
                                $html.="<div><b>Codigo de error contifico:</b> ".$resAsientoFact['response']->cod_error."</div>";
                                $html.="<div><b>DATA:</b> ".json_encode($dataAsientoFact)."</div>";
                                $accionesFallidas[] = $html;
                                //throw new Exception($html);

                            }

                        }else{

                            $accionesFallidas[] = "No se obtuvo respuesta de Contifico al momento de crear el asiento la venta ".$v->secuencial." de la empresa ".$request->company;
                            //throw new Exception("No se obtuvo respuesta de Contifico al momento de crear el asiento la venta ".$v->secuencial." de la empresa ".$request->company);

                        }
                        //FIN SE CREAN LOS ASIENTOS DE LA FACTURA

                    }else{

                        $html ="<div>Ha ocurrido un inconveniente al momento de enviar la venta <b>".$v->secuencial."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.="<div><b>Error:</b> ".$resFact['response']->mensaje." </div>" ;
                        $html.="<div><b>Codigo de error contifico:</b> ".$resFact['response']->cod_error."</div>";
                        $html.="<div><b>DATA:</b> ".json_encode($dataFactura)."</div>";

                        $accionesFallidas[] = $html;
                        $resFact['data'] = $dataFactura;

                        $connection->table('venta')
                        ->where('id_venta',$v->id_venta)
                        ->where('id_sucursal',$v->id_sucursal)
                        ->update(['id_externo' => json_encode($resFact)]);
                    }

                }else{

                    throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la venta ".$v->secuencial." de la empresa ".$request->company);

                }
                //FIN SE CREA LA FACTURA

            }

            /// NOTAS DE CREDITO ///
            $ventasNc = $connection->table('venta')
            ->where('estado',false)
            ->where('cn_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereNotNull(['secuencial_nota_credito','json_cn','id_externo'])
            ->whereRaw("CAST(json_cn->>'date_doc' AS DATE) >= ?",['2024-06-04'])->get();

            foreach ($ventasNc as $vnc) {

                $cn = json_decode($vnc->json_cn);

                $productContifico = env('PRODUCTO_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal);

                if($productContifico == null || $productContifico == '')
                    throw new Exception("No se obtuvo el producto Contifico al momento de enviar la nota de crédito ".$cn->access_key." de la venta ".$v->secuencial." de la empresa ".$company->connect." para la tienda ".$v->id_sucursal);

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
                $price = 0;
                $servicio = 0;
                $porcentajeIva = 0;

                foreach ($cn->details as $detCn) {

                    if($detCn->description == 'Servicio'){

                        $servicio += ($detCn->unit_price * $detCn->quantity) - $detCn->discount;

                    }else{

                        $price+= ($detCn->unit_price * $detCn->quantity) - $detCn->discount;
                        $porcentajeIva = $detCn->credit_note_item_tax[0]->tariff;

                        foreach ($detCn->credit_note_item_tax as $tax) {

                            if($tax->tariff == 0){

                                $base0+= (float)$tax->tax_base;

                            }else{

                                $baseMayor0+= (float)$tax->tax_base;

                            }

                        }

                    }
                }

                $dataNc = [
                    "pos" => env('API_POS_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal),
                    "fecha_emision" =>  Carbon::parse((string)$cn->date_doc)->format('d/m/Y'),
                    "tipo_documento" => "NCT",
                    "documento_relacionado_id" => $vnc->id_externo,
                    "tipo_registro" => "CLI",
                    "documento" => substr((string)$cn->access_key,24,3)."-".substr((string)$cn->access_key,27,3)."-".substr((string)$cn->access_key,30,9),
                    "autorizacion" => (string)$cn->access_key,
                    "estado" => "P",
                    "servicio" => number_format($servicio,2,'.',''),
                    "cliente" => [
                        "ruc"=>  $ruc,
                        "cedula"=>  $cedula,
                        "razon_social"=> (string)$cn->buyer_business_name,
                        "telefonos"=> $vnc->telefono_comprador,
                        "direccion"=> $vnc->direccion_comprador,
                        "tipo"=> "N",
                        "email"=> (string)$cn->emails
                    ],
                    "descripcion" => "NOTA DE CRÉDITO ".(int)substr((string)$cn->access_key,30,9),
                    "subtotal_0" => number_format($base0,2,'.',''),
                    "subtotal_12" => number_format($baseMayor0,2,'.',''),
                    "iva" => number_format((float)$cn->modified_value - (float)$cn->total_without_tax,2,'.',''),
                    "total" => number_format((float)$cn->modified_value,2,'.',''),
                    "detalles"=> [
                        [
                            "producto_id" => $productContifico,
                            "cantidad" => 1,
                            "precio" => number_format($price,2,'.',''),
                            "porcentaje_iva" => $porcentajeIva,
                            "porcentaje_descuento" => 0.00,
                            "base_cero" =>  number_format($base0,2,'.',''),
                            "base_gravable" => number_format($baseMayor0,2,'.',''),
                            "base_no_gravable" => 0.00
                        ]
                    ],
                ];

                //dd($dataNc);
                $response = self::curlStoreTransaction($dataNc,$header,env('CREAR_FACTURA_CONTIFICO'));

                if($response['response'] != null){

                    if($response['http'] == 201){

                        $connection->table('venta')
                        ->where('id_venta',$vnc->id_venta)
                        ->where('id_sucursal',$vnc->id_sucursal)
                        ->update(['cn_confirmada_externo' => true]);

                        sleep(2);

                        //SE CREAN LOS ASIENTOS DE LA NOTA DE CREDITO
                        $dataAsientoNc = [
                            "fecha" => Carbon::parse((string)$cn->date_doc)->format('d/m/Y'),
                            "glosa" => "NOTA DE CREDITO ".$dataNc['documento']." - Factura ".$cn->mofied_doc_num,
                            "gasto_no_deducible"=> 0,
                            "prefijo"=> "ASI",
                            "detalles" => [
                                [
                                    "cuenta_id" => env('CUENTA_CLIENTES_VENTAS_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal),
                                    "valor" => $cn->modified_value,
                                    "tipo"=> "H",
                                ],
                                [
                                    "cuenta_id" => env('CUENTA_IVA_VENTAS_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal),
                                    "valor" => number_format($cn->modified_value - $cn->total_without_tax,2,'.',''),
                                    "tipo"=> "D",
                                ],
                                [
                                    "cuenta_id" => env('CUENTA_PRODUCTO_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal),
                                    "valor" => number_format($price,2,'.',''),
                                    "tipo"=> "D",
                                    "centro_costo_id" => env('CENTRO_COSTO_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal)
                                ]
                            ]
                        ];

                        if($vnc->servicio > 0){

                            $dataAsientoNc['detalles'][] = [
                                "cuenta_id" => env('CUENTA_SERVICO_CONTIFICO_'.strtoupper($company->name).'_'.$vnc->id_sucursal),
                                "valor" => $servicio,
                                "tipo"=> "D",
                            ];

                        }

                        $resAsientoFact = self::curlStoreTransaction($dataAsientoNc,$header,env('CREAR_ASIENTO_CONTIFICO'));

                        if($response['response'] != null){

                            if($response['http'] == 201){

                                $accionesCompletadas[] = 'Nota de crédito '.$cn->access_key.' enviada al contifico';
                                sleep(2);

                            }else{

                                $html = "<div>Ha ocurrido un inconveniente al momento de crear la  la nota de crédito <b>".$cn->access_key."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                                $html.= "<div><b>Error:</b> ".$response['response']->mensaje." </div>" ;
                                $html.= "<div><b>Codigo de error contifico:</b> ".$response['response']->cod_error."</div>";
                                $html.="<div><b>DATA:</b> ".json_encode($dataAsientoNc)."</div>";
                                $accionesFallidas[] = $html;
                                //throw new Exception($html);

                            }

                        }else{

                            $accionesFallidas[] = "No se obtuvo respuesta de Contifico al momento de enviar la nota de crédito <b>".$vnc->access_key." de la empresa ".$request->company;
                            //throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la nota de crédito <b>".$vnc->access_key." de la empresa ".$request->company);

                        }

                        //FIN SE CREAN LOS ASIENTOS DE LA NOTA DE CREDITO

                    }else{

                        $html = "<div>Ha ocurrido un inconveniente al momento de enviar la nota de crédito <b>".$cn->access_key."</b> de la empresa <b>".$request->company."</b> a contifico </div>";
                        $html.= "<div><b>Error:</b> ".$response['response']->mensaje." </div>" ;
                        $html.= "<div><b>Codigo de error contifico:</b> ".$response['response']->cod_error."</div>";
                        $html.="<div><b>DATA:</b> ".json_encode($dataNc)."</div>";
                        $accionesFallidas[] = $html;
                        //throw new Exception($html);

                    }

                }else{

                    throw new Exception("No se obtuvo respuesta de Contifico al momento de enviar la nota de crédito <b>".$vnc->access_key." de la empresa ".$request->company);

                }

            }

            if(count($accionesCompletadas)){

                $successHtml = "Se han enviado ".count($accionesCompletadas)." de ".(count($ventas)+count($ventasNc))." documentos al contifico de la empresa ".$request->company." \n";

                foreach($accionesCompletadas as $ac)
                    $successHtml.= "<div>".$ac."</div>";

                Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico($successHtml));

            }

            if(count($accionesFallidas)){

                $htmlError = "Han ocurrido los siguiente inconvenientes al enviar las siguiente venta al contifico \n";

                foreach ($accionesFallidas as $af)
                    $htmlError.= "<div>".$af."</div>";

                Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico($htmlError,false));

            }

        } catch (\Exception $e) {
            Mail::to(env('MAIL_MONITOREO'))->send(new SendInvoicesContifico($e->getMessage(),false));
            dd($e->getMessage()."\nEn la línea: ".$e->getLine());

        }

    }

    static function curlStoreTransaction($data,$header,$url) {

        $curlClient = curl_init();

        curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curlClient, CURLOPT_URL, $url);
        curl_setopt($curlClient, CURLOPT_POST, true);
        curl_setopt($curlClient, CURLOPT_POSTFIELDS, json_encode($data));
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

    static function consultarFactura() {

        $curlClient = curl_init();

        $header = [
            'Content-Type' => 'application/json',
            'Authorization: FrguR1kDpFHaXHLQwplZ2CwTX3p8p9XHVTnukL98V5U'
        ];

        curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curlClient, CURLOPT_URL, 'https://api.contifico.com/sistema/api/v1/documento/gQbWnojwW6fmoa6w/');
        curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 30);
        $response = curl_exec($curlClient);

        $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

        curl_close($curlClient);

        dd($codigoHttp,$response);
        //
    }

}

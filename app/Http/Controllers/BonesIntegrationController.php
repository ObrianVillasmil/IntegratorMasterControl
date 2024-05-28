<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidateReceptionPurchase;
use App\Http\Requests\ValidateReceptionSales;
use App\Http\Requests\ValidateRequestCosteos;
use App\Http\Requests\ValidateRequestPurchase;
use App\Http\Requests\ValidateRequestSales;
use App\Models\Company;
use App\Models\VentaBaseImpuesto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BonesIntegrationController extends Controller
{
    public function getSales(ValidateRequestSales $request) : JsonResponse
    {
        try {

            $company = Company::find($request->company);
            $connection = DB::connection($company->connect);

            $idBranchOffice = $connection->table('empresa as e')->join('sucursal as s',function($j) {

                $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

            })->where(function($w) use($request){

                isset($request->branch_office) && $w->where('id_sucursal',$request->branch_office);

            })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

            $ventas = $connection->table('venta')
            ->where('estado',true)
            ->where('venta_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereBetween(DB::raw("fecha::date"),[$request->from,$request->to])
            ->whereNull('secuencial_nota_credito')
            ->get()->map(function($v) use($connection){

                $c = $connection->table('comprador')->where('identificacion', $v->identificacion_comprador)->first();

                if(isset($c)){

                    switch($c->id_tipo_identificacion){
                        case 1:
                            $tipoIdentificacion = 'RUC';
                            break;
                        case 2:
                            $tipoIdentificacion = 'CEDULA';
                            break;
                        case 3:
                            $tipoIdentificacion = 'PASAPORTE';
                            break;
                        default:
                            $tipoIdentificacion = 'CONSUMIDOR FINAL';
                    }

                }else{

                    $tipoIdentificacion = 'CONSUMIDOR FINAL';

                }

                $idVenta = $v->id_sucursal.'-'.$v->id_venta;

                $data = (object)[
                    "DOC_ID"=>(string) $idVenta,
                    "FECHA"=> Carbon::parse($v->fecha)->format('Y-m-d'),
                    "NUMERO"=> !isset($v->secuencial) || $v->secuencial =='' ? '*' : substr($v->secuencial,24,15),
                    "AUTORIZACION" => (string)$v->secuencial,
                    "ID_TIPO_VENTA" => (string)$v->id_cliente_externo,
                    "CUENTA" => (string)$v->identificacion_cuenta,
                    "SUBTOTAL"=> number_format($v->subtotal0+$v->subtotal12,2,'.',''),
                    "IVA"=> (string)$v->valor_impuesto,
                    "SERVICIO"=> (string)$v->servicio,
                    "TOTAL"=> (string)$v->total_a_pagar,
                    "FOLIO"=> "*",
                    "HABITACION"=> !isset($v->identificacion_cuenta) || $v->identificacion_cuenta =='' ? '*' : $v->identificacion_cuenta,
                    "HUESPED"=> "*",
                    "LLEGADA"=> "*",
                    "SALIDA"=> "*",
                    "NUMERO_MODIFICADO"=> "*",
                    "TIPO"=> "FQ",
                    "RUC"=> (string)$v->identificacion_comprador,
                    "NOMBRE"=> strtoupper($v->nombre_comprador),
                    "DIRECCION"=> !isset($v->direccion_comprador) || $v->direccion_comprador =='' ? '*' : $v->direccion_comprador,
                    "TELEFONO"=> !isset($v->telefono_comprador) || $v->telefono_comprador =='' ? '*' : $v->telefono_comprador,
                    "EMAIL"=> !isset($v->correo_comprador) || $v->correo_comprador =='' ? '*' : $v->correo_comprador,
                    "TIPO_DOCUMENTO"=> $tipoIdentificacion,
                    "DETALLE" => [],
                    "FORMA_PAGO" => []
                ];

                $detalles = $connection->table('detalle_venta')
                ->where('id_venta',$v->id_venta)
                ->where('id_sucursal',$v->id_sucursal)->get();

                $vbi = $connection->table('venta_base_impuesto')->where('id_venta',$v->id_venta)
                ->where('id_sucursal', $v->id_sucursal)->get();

                $total = $vbi->sum('valor_base');//$v->base0 + $v->base12;

                $porcentaje = 0;
                $porcentajeServicio = 0;

                if($v->descuento2 > 0)
                    $porcentaje = ($v->descuento2 * 100) / $total;

                if($v->servicio > 0)
                    $porcentajeServicio = (int)($v->servicio * 100) / $total;

                foreach ($detalles as $det) {

                    $pcp = $connection->table('pos_configuracion_producto')->where('id_producto',$det->id_producto)->first();

                    $descTotal =  $det->monto_descuento;

                    $det->monto_descuento/= $det->cantidad;

                    $det->monto_descuento+= $det->precio * ($porcentaje / 100);

                    $precioSubTotal = ($det->precio-$det->monto_descuento);

                    $imp = (($det->precio*$det->cantidad) - $descTotal) * ($det->impuesto/100);

                    $servicio = ($precioSubTotal*$det->cantidad) * ($porcentajeServicio/100);

                    $data->DETALLE[] = [
                        "DOC_ID"=> $idVenta,
                        "CODIGO"=> $det->id_sucursal.'-'.$det->id_detalle_venta,
                        "CANTIDAD"=> number_format($det->cantidad,2,'.',''),
                        "PRECIO"=> number_format($det->precio,2,'.',''),
                        "IVA"=> number_format($imp,2,'.',''),
                        "SERVICIO"=> number_format($servicio,2,'.',''),
                        "TOTAL"=> "0",
                        "DESCUENTO"=> number_format($det->monto_descuento,2,'.',''),
                        "NOMBRE"=> $det->producto,
                        "CTA_CONTABLE" => $pcp->cc_general_nombre,
                        "COD_CTA_CONTABLE" => $pcp->cc_general,
                    ];

                }

                $tipoPagos = $connection->table('venta_tipo_pago as vtp')
                ->join('tipo_pago as tp','vtp.id_tipo_pago','tp.id_tipo_pago')
                ->leftJoin('tarjeta_credito as tc', 'vtp.id_tarjeta_credito','tc.id_tarjeta_credito')
                ->where('vtp.id_venta',$v->id_venta)
                ->where('vtp.id_sucursal',$v->id_sucursal)->get();

                foreach ($tipoPagos as $tp) {

                    $tipo = '';

                    if(in_array($tp->id_tipo_pago,[1,4,6,5])){

                        $tipo = $tp->nombre;

                    }else{

                        $tipo = (!isset($tp->tipo) || $tp->tipo == '') ? '*' : $tp->tipo;

                    }

                    $data->FORMA_PAGO[] = [
                        "DOC_ID"=> (string)$idVenta,
                        "CODIGO" => (string)$tp->id_tipo_pago,///$tipo,
                        "MONTO" => number_format($tp->monto,2,'.',''),
                        "NOMBRE" => $tipo ,
                        "LOTE" => !isset($tp->lote) || $tp->lote =='' ? '*' : $tp->lote
                    ];

                }

                return $data;

            });

            $ventasNc = $connection->table('venta')
            ->where('estado',false)
            ->where('cn_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereNotNull('secuencial_nota_credito')
            ->whereRaw("CAST(json_cn->>'date_doc' AS DATE) BETWEEN ? AND ?",[$request->from,$request->to])
            ->get()->map(function($v) use($connection){

                $c = $connection->table('comprador')->where('identificacion', $v->identificacion_comprador)->first();

                if(isset($c)){

                    switch($c->id_tipo_identificacion){
                        case 1:
                            $tipoIdentificacion = 'RUC';
                            break;
                        case 2:
                            $tipoIdentificacion = 'CEDULA';
                            break;
                        default:
                            $tipoIdentificacion = 'PASAPORTE';
                    }

                }else{

                    $tipoIdentificacion = 'CONSUMIDOR FINAL';

                }

                $cn = json_decode($v->json_cn);

                $services = array_filter($cn->details, function($arr){
                    return $arr->description == 'Servicio';
                });

                $serviceAmount = 0;

                foreach ($services as $serv) {

                    $taxes = array_sum(array_column($serv->credit_note_item_tax,'amount'));
                    $serviceAmount+= ($serv->total_without_tax + $taxes);

                }

                $idCreditNote = $v->id_sucursal.'-'.$v->id_venta.'-CN-'.$cn->id_credit_note;

                $data = (object)[
                    "DOC_ID" => $idCreditNote,
                    "FECHA" => Carbon::parse($cn->date_doc)->format('Y-m-d'),
                    "NUMERO" => (string)$cn->sequential,
                    'AUTORIZACION' => (string)$cn->access_key,
                    "ID_TIPO_VENTA" => (string)$v->id_cliente_externo,
                    "CUENTA" => (string)$v->identificacion_cuenta,
                    "SUBTOTAL" => (string)$cn->total_without_tax,
                    "IVA" => (string)($cn->modified_value - $cn->total_without_tax),
                    "SERVICIO" => (string)$serviceAmount,
                    "TOTAL" => (string)$cn->modified_value,
                    "FOLIO" => "*",
                    "HABITACION" => !isset($v->identificacion_cuenta) || $v->identificacion_cuenta =='' ? '*' : $v->identificacion_cuenta,
                    "HUESPED" => "*",
                    "LLEGADA" => "*",
                    "SALIDA" => "*",
                    "NUMERO_MODIFICADO" => $cn->mofied_doc_num,
                    "TIPO" => "NC",
                    "RUC" => $cn->buyer_identification,
                    "NOMBRE" => strtoupper($cn->buyer_business_name),
                    "DIRECCION" => !isset($v->direccion_comprador) || $v->direccion_comprador =='' ? '*' : $v->direccion_comprador,
                    "TELEFONO" => !isset($v->telefono_comprador) || $v->telefono_comprador =='' ? '*' : $v->telefono_comprador,
                    "EMAIL" => !isset($cn->emails) || $cn->emails =='' ? '*' : $cn->emails,
                    "TIPO_DOCUMENTO" => $tipoIdentificacion,
                    "DETALLE" => []
                ];

                $details =  array_filter($cn->details, function($arr){
                    return (int) substr(explode('-',$arr->main_code)[1],-6) != '999999';
                });

                foreach ($details as $det) {

                    $idProduto = (int) substr(explode('-',$det->main_code)[1],-6);

                    $pcp = $connection->table('pos_configuracion_producto')->where('id_producto',$idProduto)->first();

                    $taxes = array_sum(array_column($det->credit_note_item_tax,'amount'));

                    $data->DETALLE[] = [
                        "DOC_ID"=> (string)$idCreditNote,
                        "CODIGO"=> (string)$det->id_credit_note_item,
                        "CANTIDAD"=> (string)$det->quantity,
                        "PRECIO"=> (string)$det->unit_price,
                        "IVA"=> number_format($taxes/$det->quantity,2,'.',''),
                        "SERVICIO"=> '0.00',
                        "TOTAL"=>  '0.00',
                        "DESCUENTO"=> (string)$det->discount,
                        "NOMBRE"=> $det->description,
                        "CTA_CONTABLE" => $pcp->cc_general_nombre,
                        "COD_CTA_CONTABLE" => $pcp->cc_general
                    ];

                }

                $tipoPagos = $connection->table('venta_tipo_pago as vtp')
                ->join('tipo_pago as tp','vtp.id_tipo_pago','tp.id_tipo_pago')
                ->leftJoin('tarjeta_credito as tc', 'vtp.id_tarjeta_credito','tc.id_tarjeta_credito')
                ->where('vtp.id_venta',$v->id_venta)
                ->where('vtp.id_sucursal',$v->id_sucursal)->get();

                foreach ($tipoPagos as $tp) {

                    $tipo = '';

                    if(in_array($tp->id_tipo_pago,[1,4,6,5])){

                        $tipo = $tp->nombre;

                    }else{

                        $tipo = (!isset($tp->tipo) || $tp->tipo == '') ? '*' : $tp->tipo;

                    }

                    $data->FORMA_PAGO[] = [
                        "DOC_ID"=> $idCreditNote,
                        "CODIGO" => (string)$tp->id_tipo_pago,///$tipo,
                        "MONTO" => number_format($tp->monto,2,'.',''),
                        "NOMBRE" => $tipo ,
                        "LOTE" => !isset($tp->lote) || $tp->lote =='' ? '*' : $tp->lote
                    ];

                }

                return $data;

            });

            $ventas = $ventas->merge($ventasNc);

            return response()->json([
                'msg' =>'Intervalo de ventas '.$request->from.' - '.$request->to,
                'success'=> true,
                'sales'=> $ventas,
                'cantidad' => count($ventas)
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'msg' => $e->getMessage().' '.$e->getLine().' '.$e->getFile(),
                'success' => false
            ],500);

        }

    }

    public function receptionSales(ValidateReceptionSales $request) : JsonResponse
    {
        $company = Company::find($request->company);

        $connection = DB::connection($company->connect);

        $connection->beginTransaction();

        try {

            foreach ($request->salesid as $saleId) {

                $arr = explode('-',$saleId);

                $branchOfficeId = $arr[0];
                $id = $arr[1];

                $update = ['venta_confirmada_externo' => true];

                if(isset($arr[2]) && $arr[2] == 'CN')
                    $update = ['cn_confirmada_externo' => true];

                $connection->table('venta')
                ->where('id_sucursal', $branchOfficeId)
                ->where('id_venta',$id)
                ->update($update);

            }

            $connection->commit();

            return response()->json([
                'msg' =>'Ventas actualizadas',
                'success'=> true
            ],200);

        } catch (\Exception $e) {

            $connection->rollBack();

            return response()->json([
                'msg' => $e->getMessage(),
                'success' => false
            ],500);

        }

    }

    public function getPurchase(ValidateRequestPurchase $request) : JsonResponse
    {
        try {

            $company = Company::find($request->company);
            $connection = DB::connection($company->connect);

            $idBranchOffice = $connection->table('empresa as e')->join('sucursal as s',function($j) {

                $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

            })->where(function($w) use($request){

                isset($request->branch_office) && $w->where('id_sucursal',$request->branch_office);

            })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

            $purchases = $connection->table('factura as c')
            ->where('forma','1') //FACTURAS
            ->where('estado',true)
            ->where('compra_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereBetween('fecha_factura',[$request->from,$request->to])
            ->whereRaw("NOT EXISTS(SELECT * FROM detalle_factura AS df where df.id_factura = c.id_factura AND df.id_sucursal = c.id_sucursal AND df.cantidad < 0)",[])
            ->orderBy('id_factura','asc')
            ->get()->map(function($c) use($connection){

                switch(strlen(trim($c->ruc_proveedor))){

                    case 13:
                        $tipoIdentificacion = 'RUC';
                        break;
                    case 10:
                        $tipoIdentificacion = 'CEDULA';
                        break;
                    default:
                        $tipoIdentificacion = 'PASAPORTE';
                        break;

                }

                $idPurchase = $c->id_sucursal.'-'.$c->id_factura;
                $supplier = $connection->table('proveedor')->where('id_proveedor',$c->id_proveedor)->first();

                $data = (object)[
                    "DOC_ID"=> $idPurchase,
                    "FECHA"=> $c->fecha_factura,
                    "FECHA_CADUCIDAD" => $c->fecha_expiracion_factura,
                    "NUMERO"=> !isset($c->autorizacion) || $c->autorizacion =='' ? '*' : substr($c->autorizacion,24,15),
                    'AUTORIZACION' => !isset($c->autorizacion) || $c->autorizacion =='' ? '*' : $c->autorizacion,
                    "SUBTOTAL"=> $c->sub_total,
                    "IVA"=> $c->iva_total,
                    "SERVICIO"=> '*',
                    "TOTAL"=> $c->total,
                    "FOLIO"=> "*",
                    "HABITACION"=>'*',
                    "HUESPED"=> "*",
                    "LLEGADA"=> "*",
                    "SALIDA"=> "*",
                    "NUMERO_MODIFICADO"=> "*",
                    "TIPO"=> "FC",
                    "RUC"=> $c->ruc_proveedor,
                    "NOMBRE"=> strtoupper($c->nombre_proveedor),
                    "DIRECCION"=> !isset($supplier->direccion) || $supplier->direccion =='' ? '*' : $supplier->direccion,
                    "TELEFONO"=> !isset($c->telefono_proveedor) || $c->telefono_proveedor =='' ? '*' : $c->telefono_proveedor,
                    "EMAIL"=> !isset($c->correo_proveedor) || $c->correo_proveedor =='' ? '*' : $c->correo_proveedor,
                    "TIPO_DOCUMENTO"=> $tipoIdentificacion,
                    "DETALLE" => []
                ];

                $detalles = $connection->table('detalle_factura')
                ->where('id_factura',$c->id_factura)->where('id_sucursal',$c->id_sucursal)->get();

                foreach ($detalles as $det) {

                    $item = $connection->table('item')->where('id_item',$det->id_item)->first();

                    $data->DETALLE[] = [
                        "DOC_ID"=> $idPurchase,
                        "CODIGO"=> $det->id_sucursal.'-'.$det->id_detalle_factura,
                        "CANTIDAD"=> number_format(($det->cantidad < 0 ? $det->cantidad*-1 : $det->cantidad),2,'.',''),
                        "PRECIO"=> number_format($det->neto,2,'.',''),
                        "IVA"=> number_format($det->iva,2,'.',''),
                        "SERVICIO"=> 0,
                        "TOTAL"=> number_format($det->total,2,'.',''),
                        "DESCUENTO"=> 0,
                        "NOMBRE"=> $item->nombre,
                        "CTA_CONTABLE" => $item->cc_general,
                        "COD_CTA_CONTABLE" =>  $item->cc_general_nombre,
                    ];

                }

                return $data;

            });

            $purchasesNC = $connection->table('factura as c')
            ->where('forma','2') //NC COMPRAS
            ->where('estado',true)
            ->where('compra_confirmada_externo',false)
            ->whereIn('id_sucursal',$idBranchOffice)
            ->whereBetween('fecha_factura',[$request->from,$request->to])
            ->whereRaw("EXISTS(SELECT * FROM detalle_factura AS df where df.id_factura = c.id_factura AND df.id_sucursal = c.id_sucursal AND df.cantidad < 0)",[])
            ->orderBy('id_factura','asc')
            ->get()->map(function($c) use($connection){

                switch(strlen(trim($c->ruc_proveedor))){
                    case 13:
                        $tipoIdentificacion = 'RUC';
                        break;
                    case 10:
                        $tipoIdentificacion = 'CEDULA';
                        break;
                    default:
                        $tipoIdentificacion = 'PASAPORTE';
                        break;
                }

                $idPurchase = $c->id_sucursal.'-'.$c->id_factura.'-CN';
                $supplier = $connection->table('proveedor')->where('id_proveedor',$c->id_proveedor)->first();

                $data = (object)[
                    "DOC_ID"=> $idPurchase,
                    "FECHA"=> $c->fecha_factura,
                    "NUMERO"=> !isset($c->autorizacion) || $c->autorizacion =='' ? '*' : substr($c->autorizacion,24,15),
                    'AUTORIZACION' => !isset($c->autorizacion) || $c->autorizacion =='' ? '*' : $c->autorizacion,
                    "SUBTOTAL"=> $c->sub_total,
                    "IVA"=> $c->iva_total,
                    "SERVICIO"=> '*',
                    "TOTAL"=> $c->total,
                    "FOLIO"=> "*",
                    "HABITACION"=>'*',
                    "HUESPED"=> "*",
                    "LLEGADA"=> "*",
                    "SALIDA"=> "*",
                    "NUMERO_MODIFICADO"=> "*",
                    "TIPO"=> "NC",
                    "RUC"=> $c->ruc_proveedor,
                    "NOMBRE"=> strtoupper($c->nombre_proveedor),
                    "DIRECCION"=> !isset($supplier->direccion) || $supplier->direccion =='' ? '*' : $supplier->direccion,
                    "TELEFONO"=> !isset($c->telefono_proveedor) || $c->telefono_proveedor =='' ? '*' : $c->telefono_proveedor,
                    "EMAIL"=> !isset($c->correo_proveedor) || $c->correo_proveedor =='' ? '*' : $c->correo_proveedor,
                    "TIPO_DOCUMENTO"=> $tipoIdentificacion,
                    "DETALLE" => [],
                ];

                $detalles = $connection->table('detalle_factura')
                ->where('id_factura',$c->id_factura)
                ->where('id_sucursal',$c->id_sucursal)->get();

                foreach ($detalles as $det) {

                    $item = $connection->table('item')->where('id_item',$det->id_item)->first();

                    $data->DETALLE[] = [
                        "DOC_ID"=> $idPurchase,
                        "CODIGO"=> $det->id_sucursal.'-'.$det->id_detalle_factura,
                        "CANTIDAD"=> $det->cantidad < 0 ? $det->cantidad*-1 : $det->cantidad,
                        "PRECIO"=> $det->neto,
                        "IVA"=> number_format($det->iva,2,'.',''),
                        "SERVICIO"=> 0,
                        "TOTAL"=> $det->total,
                        "DESCUENTO"=> 0,
                        "NOMBRE"=> $item->nombre,
                        "CTA_CONTABLE" => "x.x.xx.x.x",
                        "COD_CTA_CONTABLE" => "x.x.xx.x.x",
                    ];

                }

                return $data;

            });

            $purchases = $purchases->merge($purchasesNC);

            return response()->json([
                'msg' =>'Intervalo de compras '.$request->from.' - '.$request->to,
                'success'=> true,
                'purchases'=> $purchases
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'msg' => $e->getMessage(),
                'success' => false
            ],500);

        }

    }

    public function receptionPurchase(ValidateReceptionPurchase $request) : JsonResponse
    {
        $company = Company::find($request->company);

        $connection = DB::connection($company->connect);

        $connection->beginTransaction();

        try {

            foreach ($request->purchasesid as $purchaseId) {

                $arr = explode('-',$purchaseId);

                $branchOfficeId = $arr[0];
                $id = $arr[1];

                $update = ['compra_confirmada_externo' => true];

                if(isset($arr[2]) && $arr[2] == 'CN')
                    $update = ['cn_confirmada_externo' => true];

                $connection->table('factura')
                ->where('id_sucursal', $branchOfficeId)
                ->where('id_factura',$id)
                ->update($update);

            }

            $connection->commit();

            return response()->json([
                'msg' =>'Compras actualizadas',
                'success'=> true
            ],200);

        } catch (\Exception $e) {

            $connection->rollBack();

            return response()->json([
                'msg' => $e->getMessage(),
                'success' => false
            ],500);

        }

    }

    public function getCosteos(ValidateRequestCosteos $request) : JsonResponse
    {
        try {

            $company = Company::find($request->company);
            $connection = DB::connection($company->connect);

            $idBranchOffice = $connection->table('empresa as e')
            ->where('e.estatus',true)
            ->join('sucursal as s',function($j) {

                $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

            })->where(function($w) use($request){

                isset($request->branch_office) && $w->where('id_sucursal',$request->branch_office);

            })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

            $inQ = [];

            foreach($idBranchOffice as $x)
                $inQ[] = '?';

            $sql= "
                SELECT
                sci.id_sub_categoria_item,
                (
                    CASE
                    WHEN ti.tipo_transaccion = 'DETALLE_VENTA' OR ti.tipo_transaccion = 'DETALLE_PRECUENTA' THEN 'VENTA'
                    WHEN ti.tipo_transaccion = 'AUDITORIA_TOMA_FISICA_AERA_SUCURSAL' THEN 'TOMA_FISICA'
                    WHEN ti.tipo_transaccion = 'BAJA_ITEM_PRODUCTO' THEN 'BAJA'
                    ELSE ti.tipo_transaccion END
                ) AS nombre_transaccion,
                sci.nombre AS sub_categoria,
                ROUND(SUM(ti.cantidad * ti.costo_unitario)::numeric,2) AS monto,
                ti.fecha_registro::date AS fecha_transaccion,
                ti.transaccion AS tipo_transaccion
                FROM transaccion_inventario AS ti

                JOIN item AS i ON ti.id_item = i.id_item
                JOIN sub_categoria_item AS sci ON sci.id_sub_categoria_item = i.id_sub_categoria_item

                WHERE ti.fecha_registro::date BETWEEN ? AND ?
                AND ti.id_sucursal IN (".implode(',',$inQ).")
                AND ti.tipo_transaccion IN ('DETALLE_VENTA','DETALLE_PRECUENTA','BAJA_ITEM_PRODUCTO','AUDITORIA_TOMA_FISICA_AERA_SUCURSAL')

                GROUP BY
                ti.fecha_registro::date,
                sci.id_sub_categoria_item,
                ti.transaccion,
                sci.nombre,
                ( CASE
                    WHEN ti.tipo_transaccion = 'DETALLE_VENTA' OR ti.tipo_transaccion = 'DETALLE_PRECUENTA' THEN 'VENTA'
                    WHEN ti.tipo_transaccion = 'AUDITORIA_TOMA_FISICA_AERA_SUCURSAL' THEN 'TOMA_FISICA'
                    WHEN ti.tipo_transaccion = 'BAJA_ITEM_PRODUCTO' THEN 'BAJA'
                    ELSE ti.tipo_transaccion END
                )"
            ;

            $params = [$request->from,$request->to];

            foreach ($idBranchOffice as $idbo)
                $params[] = $idbo;

            $transactions = $connection->select($sql, $params);

            /* $arrCtasContable = [
                'VENTA' => [
                    'INCREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        26 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        27 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        28 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        29 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        30 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        31 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        32 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        33 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        34 => [
                            'cta_contable' => 'IVA VENTAS',
                            'cod_cta_contable' => '21050103'
                        ],
                    ],
                    'DECREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        26 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        27 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        28 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        29 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        30 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        31 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        32 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        33 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        34 => [
                            'cta_contable' => 'IVA VENTAS',
                            'cod_cta_contable' => '21050103'
                        ],
                    ]
                ],
                'TOMA_FISICA' => [
                    'INCREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ]
                    ],
                    'DECREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ]
                    ]
                ],
                'BAJA' => [
                    'INCREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        26 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        27 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        28 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        29 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        30 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        31 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        32 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        33 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        34 => [
                            'cta_contable' => 'IVA VENTAS',
                            'cod_cta_contable' => '21050103'
                        ],
                    ],
                    'DECREMENTO' => [
                        8 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        9 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        10 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        11 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        12 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        13 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        14 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        15 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        16 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        17 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        18 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        19 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        20 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        21 => [
                            'cta_contable' => 'BAR',
                            'cod_cta_contable' => '51010540'
                        ],
                        22 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        23 => [
                            'cta_contable' => 'BOUTIQUE',
                            'cod_cta_contable' => '51010541'
                        ],
                        26 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        27 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        28 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        29 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        30 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        31 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        32 => [
                            'cta_contable' => 'HOSPEDAJE 12%',
                            'cod_cta_contable' => '41020101'
                        ],
                        33 => [
                            'cta_contable' => 'HOSPEDAJE 0%',
                            'cod_cta_contable' => '41010101'
                        ],
                        34 => [
                            'cta_contable' => 'IVA VENTAS',
                            'cod_cta_contable' => '21050103'
                        ],
                    ]
                ]
            ]; */

            $transactions = collect($transactions)->map(function($obj) use(/* $arrCtasContable,  */$connection){

                /* $cta = isset($arrCtasContable[$obj->nombre_transaccion][$obj->tipo_transaccion][$obj->id_sub_categoria_item])
                ? $arrCtasContable[$obj->nombre_transaccion][$obj->tipo_transaccion][$obj->id_sub_categoria_item]
                : ['cod_cta_contable' => '']; */

                $item = $connection->table('item')->where('id_sub_categoria_item',$obj->id_sub_categoria_item)->first();
                $subCatItem = $connection->table('sub_categoria_item')->where('id_sub_categoria_item',$obj->id_sub_categoria_item)->first();

                $obj->cuenta_categoria = $item->cc_general;
                $obj->cuenta_transaccion = $subCatItem->cc_general; //$cta['cod_cta_contable'];

                return $obj;

            });


            return response()->json([
                'msg' =>'Intervalo costeo '.$request->from.' - '.$request->to,
                'success'=> true,
                'costs'=> $transactions
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'msg' => $e->getMessage(),
                'success' => false
            ],500);

        }

    }

}

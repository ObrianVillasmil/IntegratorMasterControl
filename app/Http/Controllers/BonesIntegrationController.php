<?php

namespace App\Http\Controllers;

use App\Http\Requests\ValidateReceptionSales;
use App\Http\Requests\ValidateUser;
use App\Models\Company;
use Carbon\Carbon;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class BonesIntegrationController extends Controller
{
    public function getSales(ValidateUser $request) : JsonResponse
    {
        try {

            $company = Company::find($request->company);
            $connection = DB::connection($company->connect);
            $comprador =  $connection->table('comprador');

            $idSucursales = $connection->table('empresa as e')->join('sucursal as s',function($j) {

                $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

            })->where(function($w) use($request){

                isset($request->branch_office) && $w->where('id_sucursal',$request->branch_office);

            })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

            $ventas = $connection->table('venta')
            ->where('estado',true)
            ->where('venta_confirmada_externo',false)
            ->whereIn('id_sucursal',$idSucursales)
            ->whereBetween(DB::raw("fecha::date"),[$request->from,$request->to])
            ->get()->map(function($v) use($comprador,$connection){

                $c = $comprador->where('identificacion', $v->identificacion_comprador)->first();

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
                    "DOC_ID"=> $idVenta,
                    "FECHA"=> Carbon::parse($v->fecha)->format('Y-m-d'),
                    "NUMERO"=> !isset($v->secuencial) || $v->secuencial =='' ? '*' : substr($v->secuencial,30,9),
                    "SUBTOTAL"=> $v->subtotal0+$v->subtotal12,
                    "IVA"=> $v->valor_impuesto,
                    "SERVICIO"=> $v->servicio,
                    "TOTAL"=> $v->total_a_pagar,
                    "FOLIO"=> "*",
                    "HABITACION"=> !isset($v->identificacion_cuenta) || $v->identificacion_cuenta =='' ? '*' : $v->identificacion_cuenta,
                    "HUESPED"=> "*",
                    "LLEGADA"=> "*",
                    "SALIDA"=> "*",
                    "NUMERO_MODIFICADO"=> "*",
                    "TIPO"=> "FQ",
                    "RUC"=> $v->identificacion_comprador,
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

                $total = $v->base0 + $v->base12;

                $porcentaje = 0;
                $porcentajeServicio = 0;

                if($v->descuento2 > 0)
                    $porcentaje = ($v->descuento2 * 100) / $total;

                if($v->servicio > 0)
                    $porcentajeServicio = ($v->servicio * 100) / $total;

                foreach ($detalles as $det) {

                    $det->monto_descuento+= $det->precio * ($porcentaje / 100);

                    $precioSubTotal = ($det->precio-$det->monto_descuento);

                    $imp = ($det->precio-$det->monto_descuento) * ($det->impuesto/100);

                    $servicio = $precioSubTotal * ($porcentajeServicio / 100);

                    $data->DETALLE[] = [
                        "DOC_ID"=> $idVenta,
                        "CODIGO"=> $det->id_sucursal.'-'.$det->id_detalle_venta,
                        "CANTIDAD"=> $det->cantidad,
                        "PRECIO"=> $det->precio,
                        "IVA"=> number_format($imp,2,'.',''),
                        "SERVICIO"=> number_format($servicio,2,'.',''),
                        "TOTAL"=> 0,
                        "DESCUENTO"=> number_format($det->monto_descuento,2,'.',''),
                        "NOMBRE"=> $det->producto
                    ];

                }

                $tipoPAgos = $connection->table('venta_tipo_pago as vtp')
                ->join('tipo_pago as tp','vtp.id_tipo_pago','tp.id_tipo_pago')
                ->leftJoin('tarjeta_credito as tc', 'vtp.id_tarjeta_credito','tc.id_tarjeta_credito')
                ->where('vtp.id_venta',$v->id_venta)
                ->where('vtp.id_sucursal',$v->id_sucursal)->get();

                foreach ($tipoPAgos as $tp) {

                    $tipo = '';

                    if(in_array($tp->id_tipo_pago,[1,4,6])){

                        $tipo = $tp->nombre;

                    }else{

                        $tipo = (!isset($tp->tipo) || $tp->tipo =='') ? '*' : $tp->tipo;

                    }

                    $data->FORMA_PAGO[] = [
                        "DOC_ID"=> $idVenta,
                        "CODIGO"=> $tipo,
                        "MONTO"=> number_format($tp->monto,2,'.',''),
                        "NOMBRE"=> $tipo ,
                        "LOTE" => !isset($tp->lote) || $tp->lote =='' ? '*' : $tp->lote
                    ];

                }

                return $data;

            });

            return response()->json([
                'msg' =>'Intervalo de ventas '.$request->from.' - '.$request->to,
                'success'=> true,
                'sales'=> $ventas
            ],200);

        } catch (\Exception $e) {

            return response()->json([
                'msg' => $e->getMessage(),
                'success' => false
            ],500);

        }

    }

    public function receptionSales(ValidateReceptionSales $request)
    {
        $company = Company::find($request->company);

        $connection = DB::connection($company->connect);

        $connection->beginTransaction();

        try {

            foreach ($request->salesid as $saleId) {

                $arr = explode('-',$saleId);

                $branchOfficeId = $arr[0];
                $id = $arr[1];

                $connection->table('venta')
                ->where('id_sucursal', $branchOfficeId)
                ->where('id_venta',$id)
                ->update(['venta_confirmada_externo' => true]);

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
}

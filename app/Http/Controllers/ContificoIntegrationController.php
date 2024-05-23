<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContificoIntegrationController extends Controller
{
    static function sendInvoices(Request $request)
    {
        $company = Company::where('name',$request->company)->first();
      
        /*$connection = DB::connection($company->connect);

        $idBranchOffice = $connection->table('empresa as e')->join('sucursal as s',function($j) {

            $j->on('e.id_empresa','s.id_empresa')->where('s.estatus',true);

        })->select('s.id_sucursal')->get()->pluck('id_sucursal')->toArray();

        $ventas = $connection->table('venta')
        ->where('estado',true)
        ->where('venta_confirmada_externo',false)
        ->whereIn('id_sucursal',$idBranchOffice)
        ->where(DB::raw("fecha::date"),'>=','2024-05-31')
        ->whereNull('secuencial_nota_credito')
        ->whereNotNull('secuencial')
        ->get()->map(function($v) use($connection){



        }); */

        try {

            $jsonVentas = json_encode([
                "pos" => $company->token2,
                "fecha_emision" => "22/05/2024",
                "tipo_documento" => "FAC",
                "documento" => "002-001-000001878",
                "estado" => "P",
                "autorizacion" => "2205202401179302754700120020010000018781234567819",
                "caja_id" => null,
                "cliente" => [
                    "ruc"=> "0922054366001",
                    "cedula"=> "0922054366",
                    "razon_social"=> "Jose Perez",
                    "telefonos"=> "0988800001",
                    "direccion"=> "Direccion cliente",
                    "tipo"=> "N",
                    "email"=> "cliente@contifico.com"
                ],
                "descripcion" => "FACTURA 21938",
                "subtotal_0" => 0.00,
                "subtotal_12" => 1.00,
                "iva" => 0.15,
                "ice" =>0.00,
                "servicio" => 0.00,
                "total" => 1.15,
                "detalles"=> [
                    [
                        "producto_id"=> "onPeE9ELrBT5Xep1", //Gel prueba
                        "cantidad"=> 1.00,
                        "precio"=> 1.00,
                        "porcentaje_iva"=> 15,
                        "porcentaje_descuento"=> 0.00,
                        "base_cero"=> 0.00,
                        "base_gravable"=> 1.00,
                        "base_no_gravable"=> 0.00
                    ]
                ],
                "cobros"=>[
                    [
                        "forma_cobro" => "TRA",
                        "monto" => 1.15,
                        "numero_cheque" => "",
                        "tipo_ping" => "D"
                    ]
                ]
            ]);

            $header = [
                'Content-Type' => 'application/json',
                'Authorization: '.$company->token
            ];

            $curlClient = curl_init();
            curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curlClient, CURLOPT_URL, env('ENVIAR_VENTA_AUTORIZADA_CONTIFICO'));
            curl_setopt($curlClient, CURLOPT_POST, true);
            curl_setopt($curlClient, CURLOPT_POSTFIELDS, $jsonVentas);
            curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 15);
            $response = curl_exec($curlClient);

            $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

            curl_close($curlClient);

            dump('$codigoHttp: '.$codigoHttp);
            dump('response');
            dd($response);


        } catch (\Exception $e) {

            dd("Error: ".$e->getMessage()." \n En la linea: ".$e->getLine()." \n Del archivo: ".$e->getFile());

        }

    }
}

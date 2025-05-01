<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Firebase\JWT\{JWT, Key};
use Illuminate\Http\Request;

class PedidosYaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        try {

            info("WEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n");

            $stringReq = $request->__toString();

            $arrPath = explode('order/',$request->path());

            if(!isset($arrPath[1]))
                throw new \Exception("No se ha encontrado el vendorId en la petición de PedidosYa: \n {$stringReq}");

            $vendorId = $arrPath[1];

            $company = Company::where('token',$vendorId)->first();

            if(!$company)
                throw new \Exception("No se ha encontrado la empresa registrada con el vendorId: {$vendorId}");

            if(!$company->secret_key_pedidosya)
                throw new \Exception("No se ha registrado la clave secreta de pedidosya para la empresa registrada con el vendorId: {$vendorId}");

            $jwt = $request->header('Authorization');

            if(!isset($jwt))
                throw new \Exception("No se ha encontrado el token de autorización en la petición de PedidosYa: \n {$stringReq}");

            if(strpos($jwt,'Bearer ') !== 0)
                throw new \Exception("El token de autorización de PedidosYa no es válido: \n {$stringReq}");

            $token = trim(explode('Bearer ',$jwt)[1]);

            $p1 = json_decode(base64_decode(explode('.',$token)[0]));

            $p2 = json_decode(base64_decode(explode('.',$token)[1]));

            $hJwt = new \stdClass();

            JWT::decode($jwt, new Key($company->secret_key_pedidosya, $p1->alg), $hJwt);

            if((!isset($p2->iss) || !isset($p2->iat) || !isset($hJwt->iss) || !isset($hJwt->iat)) || ($hJwt->iss != $p2->iss) || ($hJwt->iat != $p2->iat))
                throw new \Exception("El token de autorización de PedidosYa no es válido: \n {$stringReq}");



            info($request->all());





        } catch (\Exception $e) {

            info("Error en la peticion a /integracion-peya/order: \n {$e->getMessage()}");
            return response("Unauthorized",403);
        }









        info(print_r((array)$payload,true)."\n");

        return response("",200);

    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\RetrySendDeUnaNotificationMp;
use App\Models\WebhookDeuna;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeunaWebhookController extends Controller
{
    public function getNotification(Request $request)
    {
        info('integracion-deuna: '. $request->__toString());

        try {

            $content = $request->getContent();
            $data = json_decode($content);

            if(!isset($data->branchId) || $data->branchId == '' || !isset($data->posId) || $data->posId == '')
                throw new \Exception('BranchId o PosId no encontrados');

            if(!isset($data->idTransaction) || $data->idTransaction == '')
                throw new \Exception('No se ha encontrado el idTransaction en la petición');

            if(!isset($data->status) || $data->status == '')
                throw new \Exception('No se ha encontrado el status en la petición');

            if($data->status !== 'SUCCESS')
                throw new \Exception("El status en la petición es {$data->status}");

            $company = DB::table('companies')->where('token', $data->branchId)->first();

            if(!isset($company))
                throw new \Exception("No se ha encontrado la tienda en la base de datos por branchId: {$data->branchId}");

            WebhookDeuna::create([
                'connection' => $company->connect,
                'data' => $data
            ]);

            if(self::pingMp($company->connect)){

                $data->connect = $company->connect;

                RetrySendDeUnaNotificationMp::dispatchNow($data);

            }else{

                RetrySendDeUnaNotificationMp::dispatch($data)->onQueue('deuna-notification');

            }

            return response('SUCCESS',200);

        } catch (\Exception $e) {

            info("Error en la peticion a /integracion-deuna: {$e->getMessage()}");
            return response('Unauthorized',403);

        }

    }
}

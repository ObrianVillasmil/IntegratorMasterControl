<?php

namespace App\Console\Commands;

use App\Models\Company;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GetDatosContifico extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'datos:contifico';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Obtiene las cunetas contables de la empresa en contifico';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $companies = DB::table('companies')
        ->whereNull('deleted_at')
        ->select('name')->get()->pluck('name')->toArray();

        $company = $this->choice('De que empresa desea obtener datos.?',$companies );

        $data = $this->choice(
            'Que datos desea obtener.?',
            ['Plan de cuentas', 'Centro de costos']
        );

        try {

            $company = Company::where('name',$company)->first();

            $header = [
                'Content-Type' => 'application/json',
                'Authorization: '.$company->token
            ];

            $curlClient = curl_init();

            $url = '';

            if($data == 'Plan de cuentas'){

                $url = env('CONSULTAR_PLAN_CUENTAS_CONTIFICO');

            }else if($data == 'Centro de costos'){

                $url = env('CONSULTAR_CENTROS_COSTO_CONTIFICO');

            }

            curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curlClient, CURLOPT_URL, $url);
            curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 30);
            $response = curl_exec($curlClient);

            $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

            curl_close($curlClient);

            $response = json_decode($response);

            if($response!=null){

                if($codigoHttp == 200){

                    info((array)$response);
                    dd($data.' devueltos en el log');

                }else{

                    throw new Exception($response->mensaje);

                }

            }else{

                throw new Exception('No se obtuvo respuesta de contifico');

            }

        } catch (\Exception $e) {

            $this->warn($e->getMessage());

        }

    }
}

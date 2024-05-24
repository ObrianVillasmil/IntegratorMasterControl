<?php

namespace App\Console\Commands;

use App\Models\Company;
use Exception;
use Illuminate\Console\Command;

class BancosContifico extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bancos:contifico {company}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Obtiene los bancos de la empresa registrados en contifico';

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
        $company = $this->argument('company');

        if($company != null){

            try {

                $company = Company::where('name',$company)->first();

                $header = [
                    'Content-Type' => 'application/json',
                    'Authorization: '.$company->token
                ];

                $curlClient = curl_init();

                curl_setopt($curlClient, CURLOPT_HTTPHEADER, $header);
                curl_setopt($curlClient, CURLOPT_URL, env('CONSULTAR_BANCOS_CONTIFICO'));
                curl_setopt($curlClient, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curlClient, CURLOPT_CONNECTTIMEOUT, 30);
                $response = curl_exec($curlClient);

                $codigoHttp = curl_getinfo($curlClient, CURLINFO_HTTP_CODE);

                curl_close($curlClient);

                $response = json_decode($response);

                if($response!=null){

                    if($codigoHttp == 200){

                        dd($response);

                    }else{

                        throw new Exception($response->mensaje);

                    }

                }else{

                    throw new Exception('No se obtuvo respuesta de contifico al consultar los bancos');

                }

            } catch (\Exception $e) {

                $this->warn($e->getMessage());

            }

        }else{

            dd('No se obtuvo el nombre de la empresa');

        }

    }
}

<?php

namespace App\Console\Commands;

use App\Http\Controllers\Controller;
use Illuminate\Console\Command;

class ProbarPing extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'probar:ping {conexion}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prueba el ping con una conexión de base de datos configurada';

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
        $conexion = $this->argument('conexion');

        //while para obtener el valor de la conexión
        while(!config("database.connections.$conexion"))
            $conexion = $this->ask("No existe la conexión $conexion, ingrese una conexión disponible de las siguientes: ".implode(',',array_keys(config("database.connections"))));


        $this->info("Probando el ping de la conexión $conexion \n");

        if(Controller::pingMp($conexion)){

            $this->info("El ping de la conexión $conexion es correcto");

        }else{

            $this->error("El ping de la conexión $conexion no es correcto");

        }
    }
}

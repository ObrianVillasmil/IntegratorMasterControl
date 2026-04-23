<?php

namespace App\Http\Controllers;

use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public static function pingMp($conexion)
    {
        $startTime = microtime(true);
        $host = config("database.connections.$conexion.host");
        $port = 5432;
        $fp = @fsockopen($host, $port, $errno, $errstr, 5);
        $endTime = microtime(true);

        if (!$fp) {

            $texto = "Falló el ping al Host {$host}:{$port}, conexión {$conexion} no disponible. Error: $errstr. Tiempo: ".(round($endTime - $startTime, 4))." segundos";
            info("\n" . $texto . "\n");

            return false;
        }

        fclose($fp);
        return true;
        /* $comando = "ping -c 1 ".config("database.connections.$conexion.host");
        $output = shell_exec($comando);

        if(strpos($output,'1 received') !== false){

            return true;

        }else{

            info("Ping a la conexión $conexion fallida");
            info("\n Comando: ".$comando);
            info("\n".$output);
            return false;

        } */

    }

}

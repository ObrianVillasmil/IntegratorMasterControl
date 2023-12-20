<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public static function pingGefacture()
    {
        $comando = "ping -c 1 ".env('GEFACTURE');
        $output = shell_exec($comando);
        //CAMBIAR
        //return strpos($output,'1 received');
        return true;
    }


}

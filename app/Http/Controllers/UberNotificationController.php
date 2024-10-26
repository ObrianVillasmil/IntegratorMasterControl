<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Http\Request;

class UberNotificationController extends Controller
{
    public static function orderNotification(Request $request) : Array
    {
        if(isset($request->meta->user_id)){

            
          info('connection: '.$request->connect);

        }else{



        }

        return [];
    }
}

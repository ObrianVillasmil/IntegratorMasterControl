<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BonesIntegrationController;
use App\Http\Controllers\DeunaWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UberWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('api')->post('/integracion-uber', [UberWebhookController::class, 'getNotification']);
Route::middleware('api')->post('/integracion-deuna', [DeunaWebhookController::class, 'getNotification']);
Route::middleware('api')->post('/integracion-peya', function(Request $request){

    info('WEBHOOK GENERAL PEDIDOS YA');
    info("Info recibida: \n\n ".$request->__toString());

    return response("",200);

});
Route::middleware('api')->post('/integracion-peya/order/{vendorId}', function(Request $request){

    info("WEBHOOK RECEPCION DE PEDIDO PEDIDOS YA:\n\n");
    info("Info recibida: \n\n ".$request->__toString());

    return response("",200);

});

Route::group([

    'middleware' => 'api',
    'prefix' => 'auth'

], function ($router) {

    Route::post('login', [AuthController::class,'login']);

    Route::group(['middleware'=>['jwt.verify']],function(){

        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::post('me', [AuthController::class, 'me']);


        Route::get('bones-ventas',[BonesIntegrationController::class,'getSales']);
        Route::post('bones-recepcion-ventas',[BonesIntegrationController::class,'receptionSales']);

        Route::get('bones-compras',[BonesIntegrationController::class,'getPurchase']);
        Route::post('bones-recepcion-compras',[BonesIntegrationController::class,'receptionPurchase']);

        Route::get('bones-costeo',[BonesIntegrationController::class,'getCosteos']);

    });

});

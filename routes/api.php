<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BonesIntegrationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

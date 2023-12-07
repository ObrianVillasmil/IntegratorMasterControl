<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;

class Verify
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next)
    {
        try {

            $user = JWTAuth::parseToken()->authenticate();

            if( !$user ) throw new Exception('No existe el usuario');

            $token = $request->header('authorization');

            $registeredToken = DB::table('user_companies as uc')->join('companies as c',function($j) use($token){

                $j->on('uc.id_company','c.id')->where('c.token',trim(explode(' ',$token)[1]));

            })->where('uc.id_user',$user->id)->exists();

            if(!$registeredToken) throw new Exception('token no registrado');

            return $next($request);

        } catch (Exception $e) {
            if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenInvalidException){
                return response()->json(['msg'=> 'El token de seguridad es invalido para la solicutd: <b>'. $request->path().'</b>'],401);
            }else if ($e instanceof \Tymon\JWTAuth\Exceptions\TokenExpiredException){
                return response()->json(['msg'=> 'Tu sesión a expidaro, por favor vuelve a iniciar sesión'],401);
            }else{
                return response()->json(['msg'=> $e->getMessage()],401);
            }
        }
    }
}

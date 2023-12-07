<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ValidateReceptionSales extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(Request $request)
    {
        $user = auth()->user();

        return [
            'connect' => 'required|exists:companies,connect',
            'company' => ['required','numeric:min:1',function($attribute,$value,$failure) use($user){

                if(!isset($user)){

                    $failure('Usuario no logueado');

                }else{

                    $idCompanies = $user->companies->pluck('id_company')->toArray();

                    if(!in_array($value,$idCompanies))
                        $failure('Usted no tiene asignada la empresa');

                }

            },'exists:companies,id'],
            'salesid' => ['required','Array','min:1',function($attribute,$value,$failure) use($request){

                foreach($value as $saleId){

                    $arr = explode('-',$saleId);

                    $branchOfficeId = $arr[0];
                    $id = $arr[1];

                    $company = Company::find($request->company);
                    $sale =  DB::connection($company->connect)->table('venta')->where('id_sucursal', $branchOfficeId)->where('id_venta',$id)->exists();

                    if(!$sale)
                        $failure('No existe una venta con el ID '.$saleId);

                }

            }]

        ];
    }

    public function messages()
    {
        return [
            'company.requried' => 'Debe enviar el identificador de la empresa',
            'company.exists' => 'El identificador de la empresa no existe',
            'company.numeric' => 'El identificador de la empresa debe ser un número',
            'company.min' => 'El identificador de la empresa debe ser un número mayor a 0',
            'salesid.required' => 'Debe enviar al menos una venta para confirmar la recepción',
            'Array.Array' => 'Las ventas deben ser enviadas como un arreglo de datos',
            'Array.min' => 'Debe enviar al menos una venta para confirmar la recepción',
            'connect.required' => 'El nombre de la conexión es obligatorio',
            'connect.exists' => 'La conexión no existe'
        ];
    }
}

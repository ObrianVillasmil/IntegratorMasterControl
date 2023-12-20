<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ValidateReceptionPurchase extends FormRequest
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
            'company' => ['required','numeric:min:1',function($attribute,$value,$failure) use($user){

                if(!isset($user)){

                    $failure('Usuario no logueado');

                }else{

                    $idCompanies = $user->companies->pluck('id_company')->toArray();

                    if(!in_array($value,$idCompanies))
                        $failure('Usted no tiene asignada la empresa');

                }

            },'exists:companies,id'],
            'purchasesid' => ['required','Array','min:1',function($attribute,$value,$failure) use($request){

                foreach($value as $saleId){

                    $arr = explode('-',$saleId);

                    $branchOfficeId = $arr[0];
                    $id = $arr[1];

                    $company = Company::find($request->company);
                    $prurchase =  DB::connection($company->connect)->table('factura')->where('id_sucursal', $branchOfficeId)->where('id_factura',$id)->exists();

                    if(!$prurchase)
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
            'purchasesid.required' => 'Debe enviar al menos una compra para confirmar la recepción',
            'Array.Array' => 'Las ventas deben ser enviadas como un arreglo de datos',
            'Array.min' => 'Debe enviar al menos una compra para confirmar la recepción',
        ];
    }
}

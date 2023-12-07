<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ValidateUser extends FormRequest
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
            'from' =>'required|date',
            'to' =>'required|date',
            'company' => ['required','numeric:min:1',function($attribute,$value,$failure) use($user, $request){

                if(!isset($user)){

                    $failure('Usuario no logueado');

                }else{

                    $idCompanies = $user->companies->pluck('id_company')->toArray();

                    if(!in_array($value,$idCompanies)){

                        $failure('Usted no tiene asignada la empresa');

                    }else{

                        if(isset($request->branch_office)){

                            $company = Company::find($value);

                            $sucursal = DB::connection($company->connect)->table('sucursal')
                            ->where('id_sucursal',$request->branch_office)
                            ->where('estatus',true)->exists();

                            if(!$sucursal)
                                $failure('La sucursal que desea consultar no existe o está desactivada');

                        }

                    }

                }

            },'exists:companies,id']
        ];
    }

    public function messages()
    {
        return [
            'company.requried' => 'Debe enviar el identificador de la empresa',
            'company.exists' => 'El identificador de la empresa no existe',
            'company.numeric' => 'El identificador de la empresa debe ser un número',
            'company.min' => 'El identificador de la empresa debe ser un número mayor a 0',
            'from.required' => 'Debe enviar la fecha desde',
            'to.required' => 'Debe enviar la fecha hasta',
            'from.date' => 'La fecha desde debe ser en formato Y-m-d',
            'to.date' => 'La fecha hasta debe ser en formato Y-m-d',
        ];
    }

}

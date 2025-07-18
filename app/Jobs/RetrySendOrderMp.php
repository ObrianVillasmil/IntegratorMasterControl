<?php

namespace App\Jobs;

use App\Http\Controllers\ContificoIntegrationController;
use App\Http\Controllers\Controller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RetrySendOrderMp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;//, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    private $data;
    private $conexion;

    public function __construct($data, $conexion)
    {
        $this->data = $data;
        $this->conexion = $conexion;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ping = Controller::pingMp($this->conexion);

        if($ping){

            $connection = DB::connection($this->conexion);

            try {

                $existPreAccount = $connection->table('precuenta')->where('default_name',$this->data['order_id'])->first();

                if($existPreAccount)
                    throw new \Exception("Ya existe una orden con el mismo identificador {$this->data['order_id']} en la conexion {$this->conexion} ");

                $connection->beginTransaction();

                $pos = $connection->table('pos')->where('estado',true)->first();

                $customerId = null;

                if(isset($this->data['customer_identification']) && $this->data['customer_identification'] != ''){

                    $customer = $connection->table('comprador')->where('identificacion',$this->data['customer_identification'])->first();

                    switch (strlen($this->data['customer_identification'])) {
                        case 10:
                            $idType = 2;
                            break;
                        case 13:
                            $idType = 1;
                            break;
                        default:
                            $idType = 3;
                    }

                    $dataCustomer = [
                        'nombre' => $this->data['customer'],
                        'identificacion' => $this->data['customer_identification'],
                        'correo' => isset($this->data['customer_email']) ? $this->data['customer_email'] : null,
                        'telefono' => isset($this->data['customer_phone']) ? $this->data['customer_phone'] : null,
                        'direccion' => isset($this->data['customer_address']) ? $this->data['customer_address'] : null,
                        'id_tipo_identificacion' => $idType,
                        'id_sucursal' => $this->data['id_branch_office'],
                        'autorizacion_datos' => 'S'
                    ];

                    if(isset($customer)){

                        $customerId = $customer->id_comprador;

                        $connection->table('comprador')->where('identificacion',$this->data['customer_identification'])->update($dataCustomer);

                    }else{

                        $customerId = $connection->table('comprador')->orderBy('id_comprador','desc')->first()->id_comprador+1;

                        $dataCustomer['id_comprador'] = $customerId;

                        $connection->table('comprador')->insert($dataCustomer);

                    }

                }

                $prec = $connection->table('precuenta')->orderBy('id_precuenta', 'desc')->first();

                $precuentaId = !isset($prec) ? 1 : ($prec->id_precuenta+1);

                $connection->table('precuenta')->insert([
                    'id_precuenta' => $precuentaId,
                    'id_sucursal' => $this->data['id_branch_office'],
                    'nombre' => $this->data['name'],
                    'id_pos' => $pos->id_pos,
                    'id_usuario' => '1000',
                    'id_comprador' => $customerId,
                    'default_name' => $this->data['order_id'],
                    'comenzales' => 1,
                    'id_cliente_externo' => $this->data['sale_type_id'],
                    'venta_web' => true,
                    'total_venta_web' => $this->data['total'],
                    'tipo_pago_venta_web' => $this->data['payment_type_id'],
                    'tipo' => $this->data['ordering_platform'],
                    'json_descuento' => isset($this->data['json_desc_subtotal']) ? json_encode($this->data['json_desc_subtotal']) : null,
                    'base0' => 0,
                    'base12' => 0,
                    'propina' => 0,
                    'descuento1' => 0,
                    'descuento2' => 0,
                    'subtotal12' => 0,
                    'subtotal0' => 0,
                    'impuesto' => 0,
                    'servicio' => 0,
                ]);

                //SOLO PARA APPS DE DELIVERY, LOS ECCOMERCE EXTERNOS ENTRAN COMO UNA PRECUENTA NORMAL
                if(isset($this->data['app_deliverys']) && $this->data['app_deliverys']){

                    switch($this->data['ordering_platform']){
                        case 'UBER_EATS':
                            $logo = 'ubereats.webp';
                            break;
                        case 'PEDIDOS_YA':
                            $logo = 'pedidosya.png';
                            break;
                        case 'RAPPI':
                            $logo = 'rappi.webp';
                            break;
                        default:
                            $logo = 'appdelivery.webp';
                    }

                    $connection->table('precuenta_app_delivery')
                    ->where( 'id_sucursal', $this->data['id_branch_office'])
                    ->where('id_precuenta', $precuentaId)->update(['estado' => false]);

                    $connection->table('precuenta_app_delivery')->insert([
                        'id_precuenta' => $precuentaId,
                        'id_sucursal' => $this->data['id_branch_office'],
                        'estado_app' => 'OFFERED',
                        'canal' => $this->data['ordering_platform'],
                        'cuerpo' => $this->data['body'],
                        'logo' => $logo,
                        'tiempo_preparacion' => 10
                    ]);

                }

                $items = json_decode($this->data['items']);

                foreach ($items as $item) {

                    $imp = $connection->table('impuesto')->where('valor',$item->tax)->first();

                    $detPrec = $connection->table('detalle_precuenta')->orderBy('id_detalle_precuenta', 'desc')->first();

                    $detPrecuentaId = !isset($detPrec) ? 1 : ($detPrec->id_detalle_precuenta+1);

                    $connection->table('detalle_precuenta')->insert([
                        'id_detalle_precuenta' => $detPrecuentaId,
                        'id_sucursal' => $this->data['id_branch_office'],
                        'id_precuenta' => $precuentaId,
                        'id_producto' => $item->id,
                        'tipo' => $item->type,
                        'nombre' => $item->name.(isset($item->comment) && $item->comment != '' ? (' | '.$item->comment) : ''),
                        'impuesto' => $item->tax,
                        'cantidad' => $item->quantity,
                        'ingrediente' => $item->ingredient == 1,
                        'id_impuesto' => $imp->id_impuesto,
                        'comentario' => $item->comment,
                        'impreso' => false,
                        'precio' => $item->sub_total_price,
                        'id_pos_configuracion_producto_pregunta' => $item->id_pcpp,
                        'id_cajero'=> '1000',
                        'id_sucursal' => $this->data['id_branch_office'],
                        'monto_descuento' => $item->discount,
                        'json_descuento' => isset($item->json_discount) ? $item->json_discount : null
                    ]);

                }

                $sucursal = $connection->table('sucursal')->where('estatus',true)->first();

                if($sucursal->monitor){

                    $connection->table('monitor')->insert([
                        'id_precuenta' => $precuentaId,
                        'usuario' => 'Master',
                        'incio' => now()->toDateTimeString(),
                        'sonido' => false
                    ]);

                }

                $connection->commit();

            } catch (\Exception $e) {

                info('Error createMpAccount: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile());

                $connection->rollBack();

                if(strpos($e->getMessage(), 'precuenta_pk') !== false)
                    $this->fail('Id de precuenta en uso, reintentado con job '.$this->data['order_id'].' en la conexión '.$this->conexion);

                ContificoIntegrationController::sendMail([
                    'subject' => "Error en envío de pedido {$this->data['order_id']} a la conexión {$this->conexion}",
                    'sucursal' => strtoupper($this->conexion),
                    'ccEmail' => env('MAIL_NOTIFICATION'),
                    'html' => "<html>
                        <head>
                            <style>
                                .alert {
                                    padding: 15px;
                                    margin-bottom: 20px;
                                    border: 1px solid transparent;
                                    border-radius: 4px;
                                }
                                .alert-danger {
                                    color: #155724;
                                    background-color: #d4edda;
                                    border-color: #c3e6cb;
                                }
                            </style>
                        </head>
                        <body>
                            <div class='alert alert-danger' role='alert'>
                                <p> Error createMpAccount: {$e->getMessage()} {$e->getLine()} {$e->getFile()}</p>
                            </div>
                        </body>
                    </html>"
                ]);

            }

        }else{

            $this->fail('No se le pudo hacer ping a la conexión '.$this->conexion.' al crear el pedido '.$this->data['name']);

        }
    }
}

<?php

namespace App\Jobs;

use App\Http\Controllers\Controller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RetryCancelOrderMp implements ShouldQueue
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
info('$this->data');
info($this->data);
            try {

                $connection = DB::connection($this->conexion);

                $connection->beginTransaction();

                $precuenta = $connection->table('precuenta')->where('default_name',$this->data['order_id'])->first();

                $precuentaAppDelivery = $connection->table('precuenta_app_delivery')
                ->where('cuerpo->order->id',$this->data['order_id'])
                ->where('estado',true)->first();
info('$precuentaAppDelivery: ');
info($precuentaAppDelivery);
                if(isset($precuentaAppDelivery)){

                    $cuerpo = json_decode($precuentaAppDelivery->cuerpo);

                    $connection->table('precuenta')->where('default_name',$this->data['order_id'])->update(['procesado' => true]);

                    $connection->table('precuenta_app_delivery')
                    ->where('cuerpo->order->id',$this->data['order_id'])
                    ->where('estado',true)
                    ->update(['estado' => false]);

                    $status = isset($this->data['status']) ? $this->data['status'] : 'CANCELLED';

                    if(isset($cuerpo->order)){
                        $cuerpo->order->state = $status;
                    }else{
                        $cuerpo->current_status = $status;
                    }

                    $connection->table('precuenta_app_delivery')->insert([
                        'id_precuenta' => $precuenta->id_precuenta,
                        'id_sucursal' => $precuenta->id_sucursal,
                        'estado_app' => $status,
                        'cuerpo' => json_encode($cuerpo),
                        'logo' => $precuentaAppDelivery->logo,
                        'canal' => $precuentaAppDelivery->canal,
                        'tiempo_preparacion' => $precuentaAppDelivery->tiempo_preparacion,
                        'estado' => true,
                        'fecha_registro' => now()->toDateTimeString()
                    ]);

                }

                $connection->commit();

            } catch (\Exception $e) {

                info('Error deleteMpOrderAppDelivery: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile().' '.$e->getTraceAsString());

                $connection->rollBack();

            }

        }else{

            $this->fail('No se le pudo hacer ping a la conexión '.$this->conexion.' al cancelar el pedido '.$this->data['order_id']);

        }

    }

}

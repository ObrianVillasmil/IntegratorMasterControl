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

class RetryUpdateOrderMp implements ShouldQueue
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

            try {

                $connection = DB::connection($this->conexion);

                $order = $connection->table('precuenta as p')
                ->join('precuenta_app_delivery as app','p.id_precuenta','app.id_precuenta')
                ->where('p.default_name',$this->data['order_id'])->first();

                switch($this->data['ordering_platform']){
                    case 'UBER_EATS':
                        $logo = 'ubereats.webp';
                        break;
                    default:
                        $logo = 'appdelivery.webp';
                }

                $connection->table('precuenta_app_delivery')
                ->where( 'id_sucursal', $order->id_sucursal)
                ->where('id_precuenta', $order->id_precuenta)->update(['estado' => false]);

                $connection->table('precuenta_app_delivery')->insert([
                    'id_precuenta' => $order->id_precuenta,
                    'id_sucursal' => $order->id_sucursal,
                    'estado_app' => $this->data['status'],
                    'canal' => $this->data['ordering_platform'],
                    'cuerpo' => $this->data['body'],
                    'logo' => $logo,
                    'tiempo_preparacion' => $this->data['tiempo_preparacion']
                ]);

                $connection->commit();

            } catch (\Exception $e) {

                info('Error updateMpOrder: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile().' '.$e->getTraceAsString());

                $connection->rollBack();

            }

        }else{

            $this->fail('No se le pudo hacer ping a la conexión '.$this->conexion.' al actualizar el estado el pedido '.$this->data['order_id']);

        }

    }
}

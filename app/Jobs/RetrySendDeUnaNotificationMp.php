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

class RetrySendDeUnaNotificationMp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */

    public $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $ping = Controller::pingMp($this->data->connect);

        if($ping){

            $connection = DB::connection($this->data->connect);

            try {

                $connection->beginTransaction();

                $branchOffice = $connection->table('sucursal')->where('estatus',true)->first();

                $transaction = $connection->table('pago_deuna')
                ->where('transaccion_id',$this->data->idTransaction)
                ->where('referencia', $this->data->internalTransactionReference)->first();

                if(!$transaction)
                    throw new \Exception("No se ha encontrado la transacción de deuna con id: {$this->data->idTransaction} en la conexión: {$this->data->connect}");

                $connection->table('pago_deuna')->update([
                    'estado' => 'APPROVED',
                    'id_sucursal' => $branchOffice->id_sucursal,
                    'nombre_comprador' => $this->data->customerFullName,
                    'numero_transferencia' => $this->data->transferNumber,
                    'pos' => $this->data->posId,
                    'amount' => $this->data->amount,
                    'data' => json_encode($this->data)
                ]);

                $connection->table('pago_deuna_status')->insert([
                    'id_pago_deuna' => $transaction->id_pago_deuna,
                    'id_sucursal' => $branchOffice->id_sucursal,
                    'status' => 'APPROVED',
                    'id_usuario' => '1000',
                    'fecha' => now()->toDateTimeString(),
                    'data' => json_encode($this->data)
                ]);

                $connection->commit();

            } catch (\Exception $e) {

                info('Error RetrySendDeUnaNotificationMp: '. $e->getMessage().' '.$e->getLine().' '.$e->getFile().' '.$e->getTraceAsString());

                $connection->rollBack();

            }

        }else{

            $this->fail('No se le pudo hacer ping a la conexión '.$this->data->connect.' al enviar la aprobación de la transacción de deuna '.$this->data->idTransaction);

        }
    }
}

<?php

namespace App\Jobs;

use App\Http\Controllers\ContificoIntegrationController;
use App\Http\Controllers\Controller;
use App\Http\Controllers\UberNotificationController;
use App\Models\WebhookUber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RetryGetUberOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;//, SerializesModels;

    public $tries = 4;

    public function backoff()
    {
        return [5, 30, 120, 300];
    }

    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle()
    {
        $client = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer '. $this->data->token,
            'Accept: application/json'
        ];

        $params = http_build_query(['expand' => 'carts,deliveries,payment']);

        curl_setopt($client, CURLOPT_URL, $this->data->resource_href.'?'.$params);
        curl_setopt($client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($client, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($client, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($client, CURLOPT_CONNECTTIMEOUT, 15);

        $response = curl_exec($client);

        $codigoHttp = curl_getinfo($client, CURLINFO_HTTP_CODE);

        curl_close($client);

        WebhookUber::where('id',$this->data->webook_uber_id)->update(['order' => $response]);

        $decoded = json_decode($response);

        if(isset($decoded->order) && $codigoHttp >= 200 && $codigoHttp <= 299){

            $this->data->order = $response;

            UberNotificationController::orderNotification($this->data);

        } else {

            $reason = !isset($decoded->order)
                ? 'La respuesta de Uber no contiene la propiedad order'
                : "Uber respondió con HTTP {$codigoHttp}";

            info("RetryGetUberOrder intento {$this->attempts()}/{$this->tries} fallido para el webhook {$this->data->webook_uber_id}: {$reason}");
            info('$Response');
            info($response);

            if($this->attempts() < $this->tries){

                $delay = $this->backoff()[$this->attempts() - 1] ?? 30;
                $this->release($delay);

            } else {

                $this->fail("No se pudo obtener la orden de Uber tras {$this->tries} intentos. Webhook id: {$this->data->webook_uber_id}, resource: {$this->data->resource_href}, último código HTTP: {$codigoHttp}");

            }

        }

    }

    public function failed(\Throwable $exception)
    {
        try {

            ContificoIntegrationController::sendMail([
                'subject' => "Fallo definitivo al obtener orden de Uber (webhook {$this->data->webook_uber_id})",
                'sucursal' => strtoupper($this->data->connect ?? 'UBER'),
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
                            <p> No se pudo obtener la orden de Uber tras {$this->tries} intentos.</p>
                            <p> Webhook id: {$this->data->webook_uber_id}</p>
                            <p> Resource href: {$this->data->resource_href}</p>
                            <p> Error: {$exception->getMessage()}</p>
                        </div>
                    </body>
                </html>"
            ]);

        } catch (\Exception $e) {

            info('Error enviando email de fallo en RetryGetUberOrder: '.$e->getMessage());

        }
    }

}

<?php

namespace App\Console\Commands;

use App\Http\Controllers\ContificoIntegrationController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class SendInvoiceContifico extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'send_invoices:contifico {company}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía las venta a contifico';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $company = $this->argument('company');

        if($company != null){

            ContificoIntegrationController::sendInvoices(new Request([
                'company' => $company
            ]));

        }else{

            dd('No se obtuvo el nombre de la empresa');

        }


    }
}

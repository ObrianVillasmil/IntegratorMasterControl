<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SendInvoicesContifico extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public $html;
    public $success;

    public function __construct($html, $success = true)
    {
        $this->html = $html;
        $this->success = $success;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject(($this->success ? 'Completado' : 'Error en' ). ' envío de ventas a contifico')->view('view.mail_contifico');
    }
}

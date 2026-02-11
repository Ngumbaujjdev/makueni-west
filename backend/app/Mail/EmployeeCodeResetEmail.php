<?php

namespace App\Mail;

use App\Models\User;
use App\Enums\DioceseBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EmployeeCodeResetEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $newEmployeeCode;

    public function __construct(User $user, string $newEmployeeCode)
    {
        $this->user = $user;
        $this->newEmployeeCode = $newEmployeeCode;
    }

    public function build()
    {
        return $this->subject('New Employee Code - Makueni West Diocese')
                    ->markdown('emails.password.employee-code-reset')
                    ->with([
                        'user' => $this->user,
                        'newEmployeeCode' => $this->newEmployeeCode,
                        'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                        'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                        'issuedAt' => now()->format('F j, Y \a\t g:i A'),
                    ]);
    }
}

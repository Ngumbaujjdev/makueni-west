<?php

namespace App\Mail;

use App\Models\User;
use App\Enums\DioceseBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordChangedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Password Changed - Makueni West Diocese')
                    ->markdown('emails.auth.password-changed')
                    ->with([
                        'user' => $this->user,
                        'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                        'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                        'changedAt' => now()->format('F j, Y \a\t g:i A'),
                    ]);
    }
}

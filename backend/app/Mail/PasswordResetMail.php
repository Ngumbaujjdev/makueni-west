<?php

namespace App\Mail;

use App\Models\User;
use App\Enums\DioceseBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $resetToken;
    public $resetUrl;

    public function __construct(User $user, string $resetToken)
    {
        $this->user = $user;
        $this->resetToken = $resetToken;
        $this->resetUrl = config('app.login_url') . '/reset-password?token=' . $resetToken;
    }

    public function build()
    {
        return $this->subject('Password Reset Request - Makueni West Diocese')
                    ->markdown('emails.auth.password-reset')
                    ->with([
                        'user' => $this->user,
                        'resetUrl' => $this->resetUrl,
                        'token' => $this->resetToken,
                        'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                        'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                        'expiresAt' => now()->addHours(24)->format('F j, Y \a\t g:i A'),
                    ]);
    }
}

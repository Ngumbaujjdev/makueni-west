<?php

namespace App\Mail;

use App\Models\User;
use App\Enums\DioceseBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $token;
    public $resetUrl;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $this->resetUrl = config('app.login_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email);
    }

    public function build()
    {
        return $this->subject('Password Reset Request - Makueni West Diocese')
                    ->markdown('emails.password.reset')
                    ->with([
                        'user' => $this->user,
                        'resetUrl' => $this->resetUrl,
                        'token' => $this->token,
                        'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                        'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                        'expiresAt' => now()->addHours(24)->format('F j, Y \a\t g:i A'),
                    ]);
    }
}

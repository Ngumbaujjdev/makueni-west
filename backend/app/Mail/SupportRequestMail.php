<?php

namespace App\Mail;

use App\Models\User;
use App\Enums\DioceseBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SupportRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $supportMessage;
    public $requestSubject;
    public $emailType;

    public function __construct(User $user, string $message, string $subject, string $emailType = 'to_support')
    {
        $this->user = $user;
        $this->supportMessage = $message;
        $this->requestSubject = $subject;
        $this->emailType = $emailType; // 'to_support' or 'confirmation'
    }

    public function build()
    {
        if ($this->emailType === 'confirmation') {
            return $this->subject('Support Request Received - Makueni West Diocese')
                        ->markdown('emails.support.confirmation')
                        ->with([
                            'user' => $this->user,
                            'message' => $this->supportMessage,
                            'subject' => $this->requestSubject,
                            'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                            'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                            'submittedAt' => now()->format('F j, Y \a\t g:i A'),
                        ]);
        }

        // For support team
        return $this->subject('Diocese Support: ' . $this->requestSubject)
                    ->replyTo($this->user->email ?? 'noreply@makueniwestdiocese.org', $this->user->full_name)
                    ->markdown('emails.support.request')
                    ->with([
                        'user' => $this->user,
                        'message' => $this->supportMessage,
                        'subject' => $this->requestSubject,
                        'dioceseName' => config('diocese.name', 'Makueni West Diocese'),
                        'logoUrl' => DioceseBranding::MAIN_LOGO->getUrl(),
                        'submittedAt' => now()->format('F j, Y \a\t g:i A'),
                    ]);
    }
}
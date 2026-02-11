<?php

namespace App\Jobs;

use App\Mail\SupportRequestMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendSupportEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $message;
    protected $subject;

    public function __construct(User $user, string $message, string $subject = 'Support Request')
    {
        $this->user = $user;
        $this->message = $message;
        $this->subject = $subject;
    }

    public function handle()
    {
        try {
            $supportEmail = config('mail.support_address', 'support@makueniwestdiocese.org');

            // Send to support team
            Mail::to($supportEmail)->send(new SupportRequestMail(
                $this->user,
                $this->message,
                $this->subject,
                'to_support'
            ));

            // Send confirmation to user if they have email
            if ($this->user->email && filter_var($this->user->email, FILTER_VALIDATE_EMAIL)) {
                Mail::to($this->user->email)->send(new SupportRequestMail(
                    $this->user,
                    $this->message,
                    $this->subject,
                    'confirmation'
                ));
            }

            Log::info('Support request emails sent successfully', [
                'user_id' => $this->user->id,
                'user_email' => $this->user->email ?? 'none',
                'support_email' => $supportEmail,
                'subject' => $this->subject,
                'timestamp' => now()->toISOString()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send support request emails', [
                'user_id' => $this->user->id,
                'subject' => $this->subject,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Support request email job failed', [
            'user_id' => $this->user->id,
            'subject' => $this->subject,
            'error' => $exception->getMessage(),
        ]);
    }
}

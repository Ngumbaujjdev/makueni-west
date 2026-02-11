<?php

namespace App\Jobs;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendPasswordResetEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $resetToken;

    public function __construct(User $user, string $resetToken)
    {
        $this->user = $user;
        $this->resetToken = $resetToken;
    }

    public function handle()
    {
        try {
            if ($this->user && $this->user->email && filter_var($this->user->email, FILTER_VALIDATE_EMAIL)) {
                $user = $this->user->fresh();

                Mail::to($user->email)->send(new PasswordResetMail($user, $this->resetToken));

                Log::info('Password reset email sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'username' => $user->username,
                    'has_reset_token' => !empty($this->resetToken),
                    'timestamp' => now()->toISOString()
                ]);
            } else {
                Log::warning('Password reset email skipped - invalid email', [
                    'user_id' => $this->user->id,
                    'email' => $this->user->email ?? 'null',
                    'username' => $this->user->username ?? 'null'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $this->user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Password reset email job failed', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email ?? 'null',
            'error' => $exception->getMessage(),
        ]);
    }
}

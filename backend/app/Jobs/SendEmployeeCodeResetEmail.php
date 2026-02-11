<?php

namespace App\Jobs;

use App\Mail\EmployeeCodeResetEmail;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendEmployeeCodeResetEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $newEmployeeCode;

    public function __construct(User $user, string $newEmployeeCode)
    {
        $this->user = $user;
        $this->newEmployeeCode = $newEmployeeCode;
    }

    public function handle()
    {
        try {
            if ($this->user && $this->user->email && filter_var($this->user->email, FILTER_VALIDATE_EMAIL)) {
                // Reload the user with fresh data
                $user = $this->user->fresh();

                Mail::to($user->email)
                    ->send(new EmployeeCodeResetEmail($user, $this->newEmployeeCode));

                Log::info('Employee code reset email sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'username' => $user->username,
                    'new_employee_code' => $this->newEmployeeCode
                ]);
            } else {
                Log::warning('Employee code reset email skipped - invalid email', [
                    'user_id' => $this->user->id,
                    'email' => $this->user->email,
                    'username' => $this->user->username
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send employee code reset email', [
                'user_id' => $this->user->id,
                'user_email' => $this->user->email,
                'username' => $this->user->username,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Employee code reset email job failed', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'username' => $this->user->username,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

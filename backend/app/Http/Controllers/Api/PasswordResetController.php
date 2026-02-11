<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendPasswordResetEmail;
use App\Jobs\SendPasswordChangedNotification;
use App\Jobs\SendEmployeeCodeResetEmail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PasswordResetController extends Controller
{
    // ========================================
    // PUBLIC ROUTES (No Authentication Required)
    // ========================================

    /**
     * Request password reset via email
     */
    public function requestPasswordReset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = User::where('email', $request->email)->first();

            // Only proceed if user exists AND is active
            if ($user && $user->status === 'active') {
                // Generate custom reset token
                $token = Str::random(64);

                // Store the token in password_reset_tokens table
                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $user->email],
                    [
                        'email' => $user->email,
                        'token' => Hash::make($token),
                        'created_at' => Carbon::now()
                    ]
                );

                // Send password reset email using job
                SendPasswordResetEmail::dispatch($user, $token);
            }

            // ALWAYS return the same success message (prevents email enumeration)
            return successResponse(
                'If an account exists with this email, a password reset link has been sent.',
                ['email_sent' => true]
            );
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to send password reset link');
        }
    }

    /**
     * Verify if reset token is valid
     */
    public function verifyResetToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$passwordReset) {
                return errorResponse('Invalid or expired password reset token', 400);
            }

            // Check if token matches
            if (!Hash::check($request->token, $passwordReset->token)) {
                return errorResponse('Invalid password reset token', 400);
            }

            // Check if token is not expired (24 hours)
            if (Carbon::parse($passwordReset->created_at)->addHours(24)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return errorResponse('Password reset token has expired', 400);
            }

            return successResponse('Password reset token is valid', [
                'token_valid' => true,
                'expires_at' => Carbon::parse($passwordReset->created_at)->addHours(24)->toISOString()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to verify reset token', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to verify reset token');
        }
    }

    /**
     * Reset password using token
     */
    public function resetPasswordWithToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
            'token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return errorResponse('User not found', 404);
            }

            if ($user->status === 'inactive') {
                return errorResponse('User account is inactive', 403);
            }

            // Check if token exists and is valid
            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$passwordReset) {
                return errorResponse('Invalid or expired password reset token', 400);
            }

            // Check if token matches
            if (!Hash::check($request->token, $passwordReset->token)) {
                return errorResponse('Invalid password reset token', 400);
            }

            // Check if token is not expired (24 hours)
            if (Carbon::parse($passwordReset->created_at)->addHours(24)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return errorResponse('Password reset token has expired', 400);
            }

            // Check if new password is same as current password
            if (Hash::check($request->password, $user->password)) {
                return errorResponse('New password must be different from current password', 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'login_attempts' => 0,
                'password_expires_at' => now()->addMonths(6), // Diocese users get 6 months
            ]);

            // Delete the used token
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Revoke all existing tokens for security
            $user->tokens()->delete();

            // Send password change confirmation email
            SendPasswordChangedNotification::dispatch($user);

            return successResponse(
                'Password has been reset successfully. Please login with your new password.',
                [
                    'password_reset' => true,
                    'tokens_revoked' => true,
                    'email_sent' => true
                ]
            );
        } catch (\Exception $e) {
            Log::error('Failed to reset password', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to reset password');
        }
    }

    /**
     * Request new employee code via email
     */
    public function requestEmployeeCodeReset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = User::where('email', $request->email)->first();

            if ($user && $user->status === 'active') {
                // Generate new 6-digit employee code
                do {
                    $newEmployeeCode = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
                } while (User::where('employee_code', $newEmployeeCode)->exists());

                // Update user's employee code
                $user->update(['employee_code' => $newEmployeeCode]);

                // Send new employee code via email
                SendEmployeeCodeResetEmail::dispatch($user, $newEmployeeCode);
            }

            // Always return success message
            return successResponse(
                'If an account exists with this email, a new employee code has been sent.',
                ['email_sent' => true]
            );
        } catch (\Exception $e) {
            Log::error('Failed to send employee code reset', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to send employee code reset');
        }
    }

    // ========================================
    // PROTECTED ROUTES (User Authentication Required)
    // ========================================

    /**
     * User changes their own password
     */
    public function changeOwnPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();

            // Check if new password is same as current password
            if (Hash::check($request->new_password, $user->password)) {
                return errorResponse('New password must be different from current password', 400);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password),
                'password_changed_at' => now(),
                'must_change_password' => false,
                'password_expires_at' => now()->addMonths(6),
            ]);

            // Send password change confirmation
            SendPasswordChangedNotification::dispatch($user);

            return successResponse('Password changed successfully');
        } catch (\Exception $e) {
            Log::error('Failed to change own password', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to change password');
        }
    }

    /**
     * User changes their own employee code
     */
    public function changeOwnEmployeeCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_employee_code' => 'required|string|size:6|unique:users,employee_code',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $user = $request->user();

            // Verify current password
            if (!Hash::check($request->current_password, $user->password)) {
                return errorResponse('Current password is incorrect', 400);
            }

            // Validate employee code format (6 digits)
            if (!preg_match('/^[0-9]{6}$/', $request->new_employee_code)) {
                return errorResponse('Employee code must be exactly 6 digits', 400);
            }

            // Update employee code
            $user->update(['employee_code' => $request->new_employee_code]);

            return successResponse('Employee code changed successfully', [
                'new_employee_code' => $request->new_employee_code
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to change own employee code', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to change employee code');
        }
    }

    // ========================================
    // ADMIN ROUTES (Admin Authentication Required)
    // ========================================

    /**
     * Admin resets any user's password
     */
    public function adminResetUserPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $admin = $request->user();
            $user = User::find($request->user_id);

            // Check admin permissions (you might want to add role checking here)
            if (!$admin->hasGlobalAccess() && !$admin->hasRole(['Global Administrator', 'Bishop'])) {
                return errorResponse('Insufficient permissions to reset user passwords', 403);
            }

            // Update password
            $user->update([
                'password' => Hash::make($request->new_password),
                'password_changed_at' => now(),
                'must_change_password' => true, // Force user to change on next login
                'login_attempts' => 0,
                'password_expires_at' => now()->addMonths(6),
            ]);

            // Revoke all existing tokens
            $user->tokens()->delete();

            // Send notification to user
            SendPasswordChangedNotification::dispatch($user);

            Log::info('Admin reset user password', [
                'admin_id' => $admin->id,
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);

            return successResponse('User password reset successfully', [
                'user_notified' => true,
                'force_change_required' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to admin reset user password', [
                'admin_id' => $request->user()->id,
                'user_id' => $request->user_id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to reset user password');
        }
    }

    /**
     * Admin changes any user's employee code
     */
    public function adminChangeEmployeeCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'new_employee_code' => 'required|string|size:6|unique:users,employee_code',
        ]);

        if ($validator->fails()) {
            return validationErrorResponse($validator->errors());
        }

        try {
            $admin = $request->user();
            $user = User::find($request->user_id);

            // Check admin permissions
            if (!$admin->hasGlobalAccess() && !$admin->hasRole(['Global Administrator', 'Bishop'])) {
                return errorResponse('Insufficient permissions to change employee codes', 403);
            }

            // Validate employee code format
            if (!preg_match('/^[0-9]{6}$/', $request->new_employee_code)) {
                return errorResponse('Employee code must be exactly 6 digits', 400);
            }

            // Update employee code
            $user->update(['employee_code' => $request->new_employee_code]);

            Log::info('Admin changed user employee code', [
                'admin_id' => $admin->id,
                'user_id' => $user->id,
                'new_employee_code' => $request->new_employee_code
            ]);

            return successResponse('Employee code changed successfully', [
                'user_id' => $user->id,
                'new_employee_code' => $request->new_employee_code
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to admin change employee code', [
                'admin_id' => $request->user()->id,
                'user_id' => $request->user_id,
                'error' => $e->getMessage()
            ]);

            return serverErrorResponse('Failed to change employee code');
        }
    }
}

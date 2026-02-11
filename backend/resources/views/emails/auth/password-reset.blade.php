@component('mail::message')

<div style="text-align: center; margin-bottom: 30px;">
    <img src="{{ $logoUrl }}" alt="Makueni West Diocese" style="max-width: 120px;">
</div>

# Password Reset Request

Dear {{ $user->full_name }},

We received a request to reset your password for the {{ $dioceseName }} Management System.

**Account Information:**
- **Email:** {{ $user->email }}
- **Employee Code:** {{ $user->employee_code ?? 'Not set' }}
- **Request Time:** {{ now()->format('F j, Y \a\t g:i A') }}

Click the button below to reset your password. This link will expire at {{ $expiresAt }}.

@component('mail::button', ['url' => $resetUrl])
Reset Password
@endcomponent

**Security Notice:**
- If you didn't request this reset, you can safely ignore this email
- This link will expire in 24 hours for security reasons
- Never share this reset link with anyone

**Alternative Access:**
Remember, you can also access the system using your Employee Code without a password if you have one assigned.

God's blessings,<br>
{{ $dioceseName }} IT Team

---
*This is an automated email. Please do not reply.*

@endcomponent

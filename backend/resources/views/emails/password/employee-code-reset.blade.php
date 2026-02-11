@component('mail::message')

<div style="text-align: center; margin-bottom: 30px;">
    <img src="{{ $logoUrl }}" alt="Makueni West Diocese" style="max-width: 120px;">
</div>

# New Employee Code Issued

Dear {{ $user->full_name }},

Your new Employee Code for the {{ $dioceseName }} Management System has been generated.

**Your New Employee Code:** `{{ $newEmployeeCode }}`

**Account Details:**
- **Name:** {{ $user->full_name }}
- **Email:** {{ $user->email }}
- **Position:** {{ $user->position ?? 'Not specified' }}
- **Issued on:** {{ $issuedAt }}

You can now use this 6-digit code to access the system without needing a password. Simply use the "Employee Code Login" option on the login page.

@component('mail::button', ['url' => config('app.login_url')])
Access System
@endcomponent

**Important Notes:**
- Keep this Employee Code secure and confidential
- You can change this code anytime through your profile settings
- This code is unique to you within the diocese system
- If you suspect unauthorized use, contact support immediately

**Login Options:**
- **Employee Code Login:** Use your 6-digit code ({{ $newEmployeeCode }})
- **Standard Login:** Use your email/username and password

God's blessings,<br>
{{ $dioceseName }} IT Team

---
*This is an automated notification from the Diocese Management System.*

@endcomponent

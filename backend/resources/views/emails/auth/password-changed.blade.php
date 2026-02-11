@component('mail::message')

<div style="text-align: center; margin-bottom: 30px;">
    <img src="{{ $logoUrl }}" alt="Makueni West Diocese" style="max-width: 120px;">
</div>

# Password Changed Successfully

Dear {{ $user->full_name }},

Your password for the {{ $dioceseName }} Management System has been successfully changed.

**Details:**
- **Changed on:** {{ $changedAt }}
- **Account:** {{ $user->email ?? $user->username }}
- **Employee Code:** {{ $user->employee_code ?? 'Not set' }}

If you did not make this change, please contact the Diocese support team immediately.

@component('mail::button', ['url' => config('app.login_url')])
Access System
@endcomponent

God's blessings,<br>
{{ $dioceseName }} IT Team

---
*This is an automated notification from the Diocese Management System.*

@endcomponent

@component('mail::message')

<div style="text-align: center; margin-bottom: 30px;">
    <img src="{{ $logoUrl }}" alt="Makueni West Diocese" style="max-width: 120px;">
</div>

# Support Request Received

Dear {{ $user->full_name }},

Thank you for contacting {{ $dioceseName }} support. We have received your request and our team will respond as soon as possible.

**Your Request:**
- **Subject:** {{ $subject }}
- **Submitted:** {{ $submittedAt }}
- **Reference:** #SR{{ str_pad($user->id, 4, '0', STR_PAD_LEFT) }}-{{ now()->format('md') }}

**Your Message:**
> {{ $message }}

**What happens next?**
Our support team will review your request and respond within 24-48 hours. If your issue is urgent, please contact your regional overseer directly.

@component('mail::button', ['url' => config('app.login_url')])
Access System
@endcomponent

God's blessings,<br>
{{ $dioceseName }} Support Team

---
*This is an automated confirmation. Please do not reply to this email.*

@endcomponent

@component('mail::message')

<div style="text-align: center; margin-bottom: 30px;">
    <img src="{{ $logoUrl }}" alt="Makueni West Diocese" style="max-width: 120px;">
</div>

# New Support Request

A new support request has been submitted to the {{ $dioceseName }} Management System.

**Request Details:**
- **From:** {{ $user->full_name }}
- **Email:** {{ $user->email ?? 'Not provided' }}
- **Employee Code:** {{ $user->employee_code ?? 'Not set' }}
- **Position:** {{ $user->position ?? 'Not specified' }}
- **Submitted:** {{ $submittedAt }}

**Subject:** {{ $subject }}

**Message:**
> {{ $message }}

**User Territory Information:**
@if($user->activeAssignments->isNotEmpty())
@foreach($user->activeAssignments as $assignment)
- **{{ $assignment->role->name }}** at {{ $assignment->territory->name }}
@endforeach
@else
- No territorial assignments found
@endif

Please respond to this request promptly.

@component('mail::button', ['url' => config('app.url') . '/admin/support'])
View in Admin Panel
@endcomponent

{{ $dioceseName }} IT Team

@endcomponent

{{--
    The email a church, region or the diocese sends (Settings > Communication,
    S6b; messages-spec L5c). esoma-server's master layout, in our brand: the
    CCI mark | the place's name and a small line, a dark title band, the
    message, an optional facts box and button, and a footer. No stripes.
    Inline styles only - mail clients ignore stylesheets.
    $heading, $lines (paragraphs), $placeName; optional $brand (EmailBrand::for),
    $badge (a short word over the heading), $details ([[label, value]]) and $button ([label, url]).
--}}
@php
    $font = "Inter,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $brand = $brand ?? ['logo' => asset(\App\Support\Messaging\EmailBrand::LOGO), 'name' => $placeName, 'sub' => 'Makueni West Diocese'];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $heading }}</title>
<style>
    @media (max-width: 620px) {
        .pad { padding-left: 20px !important; padding-right: 20px !important; }
        .h1 { font-size: 19px !important; }
        .btn { display: block !important; text-align: center !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#EEF1F6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F6;">
    <tr><td align="center" style="padding:28px 12px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:14px;overflow:hidden;border:1px solid #E2E8F0;">
            {{-- Who it is from: the CCI mark | the place --}}
            <tr>
                <td class="pad" style="padding:18px 32px;background:#FFFFFF;">
                    <table role="presentation" cellpadding="0" cellspacing="0">
                        <tr>
                            <td valign="middle"><img src="{{ $brand['logo'] }}" alt="CCI" width="40" height="38" style="display:block;width:40px;height:auto;border:0;"></td>
                            <td valign="middle" style="padding:0 12px;"><div style="width:1px;height:30px;background:#DCE5EE;font-size:0;line-height:0;">&nbsp;</div></td>
                            <td valign="middle" style="font-family:{!! $font !!};">
                                <div style="font-size:16px;line-height:1.2;font-weight:800;color:#0D0D0D;">{{ $brand['name'] }}</div>
                                <div style="margin-top:3px;font-size:10.5px;line-height:1.2;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#4A5A6E;">{{ $brand['sub'] }}</div>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            {{-- What it is about --}}
            <tr>
                <td class="pad" style="padding:22px 32px 20px;background:#0D0D0D;">
                    @if (! empty($badge))
                        <span style="display:inline-block;margin:0 0 10px;padding:4px 11px;border-radius:99px;background:#2CA4BF;font-family:{!! $font !!};font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#FFFFFF;">{{ $badge }}</span>
                    @endif
                    <div class="h1" style="font-family:{!! $font !!};font-size:21px;line-height:1.3;font-weight:800;color:#FFFFFF;">{{ $heading }}</div>
                </td>
            </tr>
            {{-- The message --}}
            <tr>
                <td class="pad" style="padding:28px 32px 12px;font-family:{!! $font !!};font-size:15px;line-height:1.65;color:#2B3440;">
                    @foreach ($lines as $line)
                        <p style="margin:0 0 14px;">{!! nl2br(e($line), false) !!}</p>
                    @endforeach
                    @if (! empty($details))
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 20px;border-radius:10px;background:#F4F7FA;">
                            @foreach ($details as [$label, $value])
                                <tr>
                                    <td style="padding:{{ $loop->first ? '14px' : '4px' }} 16px {{ $loop->last ? '14px' : '4px' }};font-size:13px;color:#4A5A6E;width:42%;">{{ $label }}</td>
                                    <td style="padding:{{ $loop->first ? '14px' : '4px' }} 16px {{ $loop->last ? '14px' : '4px' }};font-size:15px;font-weight:800;color:#0D0D0D;font-family:Menlo,Consolas,monospace;">{{ $value }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @if (! empty($button))
                        <p style="margin:4px 0 18px;"><a class="btn" href="{{ $button[1] }}" style="display:inline-block;padding:12px 22px;border-radius:8px;background:#2CA4BF;color:#FFFFFF;font-weight:700;font-size:15px;text-decoration:none;">{{ $button[0] }}</a></p>
                    @endif
                </td>
            </tr>
            {{-- Footer, on a pale band --}}
            <tr>
                <td class="pad" style="padding:16px 32px 20px;background:#F7F9FB;font-family:{!! $font !!};font-size:12px;line-height:1.7;color:#4A5A6E;">
                    @if (! empty($brand['reply_to']))
                        Questions? Reply to this email.<br>
                    @endif
                    Sent by {{ $placeName }} through the Makueni West Diocese system.
                </td>
            </tr>
        </table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">
            <tr>
                <td align="center" style="padding:16px 8px 0;font-family:{!! $font !!};font-size:11.5px;line-height:1.6;color:#6B7684;">
                    Christian Church International · Makueni West Diocese<br>
                    © {{ now()->year }} All rights reserved.
                </td>
            </tr>
        </table>
    </td></tr>
</table>
</body>
</html>

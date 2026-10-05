{{--
    A plain, email-safe message sent for a church or region (Settings >
    Communication, S6b). Inline styles only - mail clients ignore stylesheets.
    $heading, $lines (paragraphs), $placeName; optional $details ([[label, value]]) and $button ([label, url]).
--}}
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Inter,Segoe UI,Arial,sans-serif;color:#0d0d0d;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;border:1px solid #e4e9ee;overflow:hidden;">
            <tr><td style="background:#2CA4BF;height:6px;font-size:0;line-height:0;">&nbsp;</td></tr>
            <tr><td style="padding:24px 28px 8px;">
                <div style="font-size:13px;font-weight:600;color:#2CA4BF;letter-spacing:.04em;text-transform:uppercase;">{{ $placeName }}</div>
                <h1 style="margin:6px 0 0;font-size:20px;line-height:1.3;color:#0d0d0d;">{{ $heading }}</h1>
            </td></tr>
            <tr><td style="padding:8px 28px 4px;font-size:15px;line-height:1.6;">
                @foreach ($lines as $line)
                    <p style="margin:0 0 12px;">{{ $line }}</p>
                @endforeach
            </td></tr>
            @if (! empty($details))
                <tr><td style="padding:4px 28px 8px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f8fb;border-radius:8px;">
                        @foreach ($details as [$label, $value])
                            <tr>
                                <td style="padding:10px 14px;font-size:13px;font-weight:600;width:42%;">{{ $label }}</td>
                                <td style="padding:10px 14px;font-size:15px;font-weight:700;font-family:Menlo,Consolas,monospace;">{{ $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td></tr>
            @endif
            @if (! empty($button))
                <tr><td style="padding:12px 28px 4px;">
                    <a href="{{ $button[1] }}" style="display:inline-block;background:#2CA4BF;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px;padding:11px 22px;border-radius:8px;">{{ $button[0] }}</a>
                </td></tr>
            @endif
            <tr><td style="padding:20px 28px 24px;font-size:12px;line-height:1.5;color:#3d4650;border-top:1px solid #e4e9ee;">
                Sent by {{ $placeName }} through the Makueni West Diocese system.
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>

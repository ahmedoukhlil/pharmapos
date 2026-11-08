<!DOCTYPE html>
<html lang="fr">
<head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body style="margin:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 12px"><tr><td align="center">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">
        <tr><td style="background:#065f46;color:#ffffff;padding:16px 20px;font-size:18px;font-weight:bold">PharmaPOS</td></tr>
        <tr><td style="padding:20px">
            <h1 style="margin:0 0 12px;font-size:18px;color:#065f46">{{ $title }}</h1>
            <p style="margin:0 0 12px;font-size:14px;line-height:1.5">{{ $intro }}</p>
            @if ($lines)
                <ul style="margin:0 0 12px;padding-left:20px;font-size:14px;line-height:1.6">
                    @foreach ($lines as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif
            @if ($actionUrl)
                <p style="margin:16px 0 0"><a href="{{ $actionUrl }}" style="display:inline-block;background:#047857;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:bold;font-size:14px">{{ $actionLabel ?? 'Ouvrir PharmaPOS' }}</a></p>
            @endif
        </td></tr>
        <tr><td style="padding:12px 20px;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0">Message automatique de PharmaPOS. Ne répondez pas à cet e-mail.</td></tr>
    </table>
</td></tr></table>
</body>
</html>

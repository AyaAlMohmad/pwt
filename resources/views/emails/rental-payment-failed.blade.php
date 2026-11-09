<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Rental Payment Unsuccessful</title>
    <style>
        @media only screen and (max-width: 640px) {
            .email-outer { padding: 16px 10px !important; }
            .email-content { padding: 26px 23px 30px !important; }
            .email-header { padding: 28px 16px 25px !important; }
            .email-title { font-size: 21px !important; }
            .email-copy { font-size: 15px !important; line-height: 1.6 !important; }
            .summary-cell, .summary-value { font-size: 13px !important; }
            .action-link { display: block !important; text-align: center !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f3f6fa;color:#223754;font-family:Arial,Helvetica,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#f3f6fa;">Action required: please review your PWT rental payment details.</div>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;background-color:#f3f6fa;border-collapse:collapse;">
<tr><td align="center" class="email-outer" style="padding:36px 14px 48px;">
    <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;width:100%;background-color:#ffffff;border-collapse:separate;border-spacing:0;border:1px solid #e4e9f0;border-radius:16px;overflow:hidden;">
        <tr><td align="center" class="email-header" style="background-color:#203c63;padding:34px 20px 31px;">
            <table role="presentation" align="center" dir="ltr" border="0" cellpadding="0" cellspacing="0">
                <tr>
                    <td valign="middle" style="padding-right:16px;vertical-align:middle;">
                        <img src="{{ $message->embed(public_path('logo-email.png')) }}" alt="PWT Mega Technologies" width="52" style="display:block;width:52px;height:auto;border:0;">
                    </td>
                    <td valign="middle" style="text-align:left;vertical-align:middle;">
                        <div style="color:#ffffff;font-weight:700;font-size:28px;line-height:1.25;letter-spacing:0.2px;">PWT Mega Tech</div>
                    </td>
                </tr>
            </table>
        </td></tr>
        <tr><td bgcolor="#60b9d5" height="4" style="height:4px;font-size:1px;line-height:1px;background-color:#60b9d5;">&nbsp;</td></tr>
        <tr><td class="email-content" style="padding:32px 40px 35px;">
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;">
                <tr>
                    <td width="37" valign="middle" style="width:37px;vertical-align:middle;">
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="30" height="30" style="width:30px;height:30px;border-radius:50%;background-color:#fff0f0;border-collapse:collapse;">
                            <tr><td align="center" valign="middle" style="color:#c63842;font-size:19px;line-height:30px;font-weight:bold;">!</td></tr>
                        </table>
                    </td>
                    <td valign="middle" class="email-title" style="color:#203c63;font-size:24px;line-height:1.3;font-weight:700;vertical-align:middle;">Rental Payment Unsuccessful</td>
                </tr>
            </table>
            <p class="email-copy" style="margin:26px 0 14px;font-size:15px;line-height:1.65;color:#223754;">Dear {{ $details['customer_name'] }},</p>
            <p class="email-copy" style="margin:0 0 27px;font-size:15px;line-height:1.7;color:#223754;">We were unable to process your automatic monthly rental payment for your PWT water dispenser. Please review the details below.</p>
            <p style="font-size:17px;font-weight:700;color:#203c63;line-height:1.4;margin:0 0 13px;">Payment Summary</p>
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="width:100%;border-collapse:separate;border-spacing:0;background-color:#f5f7fa;border:1px solid #e1e8f1;border-radius:12px;">
                @foreach (['Machine' => $details['machine_name'].' - #'.$details['machine_id'], 'Payment Amount' => $details['currency'].' '.number_format((float) $details['amount'], 2), 'Payment Status' => 'Unsuccessful', 'Reason' => $details['failure_reason']] as $label => $value)
                <tr>
                    <td class="summary-cell" style="padding:17px 7px 18px 18px;color:#647891;font-size:14px;vertical-align:top;width:40%;{{ !$loop->last ? 'border-bottom:1px solid #dde5ee;' : '' }}">{{ $label }}</td>
                    <td class="summary-value" align="right" style="padding:17px 18px 18px 7px;color:{{ $label === 'Payment Status' ? '#c63842' : '#223754' }};font-size:14px;font-weight:600;line-height:1.5;vertical-align:top;overflow-wrap:anywhere;{{ !$loop->last ? 'border-bottom:1px solid #dde5ee;' : '' }}">{{ $value }}</td>
                </tr>
                @endforeach
            </table>
            <p style="font-size:17px;font-weight:700;color:#203c63;line-height:1.4;margin:29px 0 13px;">What should you do next?</p>
            <p class="email-copy" style="margin:0 0 21px;font-size:15px;line-height:1.7;color:#223754;">Please log in to your PWT account to review your payment details, update your payment method if needed, and complete the outstanding payment.</p>
            @if (!empty($details['payment_url']))
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse:separate;border-spacing:0;">
                <tr><td align="center" bgcolor="#203c63" style="background-color:#203c63;border-radius:9px;">
                    <a href="{{ $details['payment_url'] }}" class="action-link" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:17px 25px;color:#ffffff;background-color:#203c63;border-radius:9px;font-size:15px;line-height:1.3;font-weight:700;text-decoration:none;">Review Payment Details</a>
                </td></tr>
            </table>
            @endif
            <p style="margin:25px 0 10px;font-size:13px;line-height:1.6;color:#647891;">If you have already completed the payment, please disregard this notice.</p>
            <p class="email-copy" style="margin:0;font-size:15px;line-height:1.6;color:#223754;">Thank you for choosing PWT Mega Tech.</p>
        </td></tr>
        <tr><td align="center" style="padding:21px 24px 24px;background-color:#f5f7fa;border-top:1px solid #e2e8f0;">
            <p style="margin:0 0 14px;color:#203c63;font-size:13px;line-height:1.5;">Need assistance? Contact PWT Customer Support.</p>
            <p style="margin:0 0 5px;color:#72849e;font-size:12px;line-height:1.5;">&copy; {{ date('Y') }} PWT Mega Tech. All rights reserved.</p>
            <p style="margin:0;color:#72849e;font-size:12px;line-height:1.5;">This is an automated notification. Please do not reply to this email.</p>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>

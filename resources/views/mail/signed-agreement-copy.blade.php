{{--
    The customer's copy of their signed agreement (slice 36, step 36a;
    decision 214), as an email. Styles are inline, for email programs.
    Everything is escaped; nothing in it runs.
--}}
@php
    $text = 'margin:0 0 12px;font-size:15px;line-height:1.6;color:#27272a;';
    $small = 'margin:0 0 8px;font-size:13px;line-height:1.6;color:#3f3f46;';
    $label = 'margin:0;font-size:12px;color:#71717a;';
    $heading = 'margin:24px 0 8px;font-size:15px;font-weight:bold;color:#18181b;';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $wording->title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;">
                <tr>
                    <td style="padding:28px 28px 8px;">
                        <p style="margin:0 0 16px;font-size:18px;font-weight:bold;color:#18181b;">{{ $company }}</p>
                        <p style="{{ $text }}">{{ __('Hello :name,', ['name' => $agreement->signer_name]) }}</p>
                        <p style="{{ $text }}">{{ __('Thank you for choosing :company. Here is a copy of the agreement you signed on :when.', ['company' => config('mail.customers.from_name'), 'when' => $signedOn]) }}</p>
                        <p style="{{ $text }}">{{ __('If you have a question, reply to this email to reach :name.', ['name' => $salespersonName]) }}</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e4e7;border-radius:10px;">
                            <tr>
                                <td style="padding:18px;">
                                    <p style="{{ $label }}">{{ __('Customer') }}</p>
                                    <p style="{{ $text }}">{{ $details['customer'] ?? '—' }}</p>
                                    <p style="{{ $label }}">{{ __('Property') }}</p>
                                    <p style="{{ $text }}">{{ $details['property'] ?? '—' }}</p>

                                    <p style="{{ $label }}">{{ __(':type estimate', ['type' => $details['estimate_type']]) }}</p>
                                    <p style="margin:0 0 8px;font-size:18px;font-weight:bold;color:#18181b;">{{ $details['option']['name'] }}</p>
                                    @if ($details['option']['description'])
                                        <p style="{{ $text }}white-space:pre-line;">{{ $details['option']['description'] }}</p>
                                    @endif

                                    @if ($details['areas'] !== [])
                                        <p style="{{ $heading }}">{{ __('Rooms and areas') }}</p>
                                        <p style="{{ $text }}">{{ implode(', ', $details['areas']) }}</p>
                                    @endif

                                    @if ($details['line_items'] !== [])
                                        <p style="{{ $heading }}">{{ __('Details') }}</p>
                                        @foreach ($details['line_items'] as $item)
                                            <p style="margin:0 0 2px;font-size:15px;font-weight:bold;color:#27272a;">{{ $item['name'] }}</p>
                                            @if ($item['description'])
                                                <p style="{{ $small }}white-space:pre-line;">{{ $item['description'] }}</p>
                                            @endif
                                        @endforeach
                                    @endif

                                    <p style="{{ $heading }}">{{ __('Price') }}</p>
                                    <p style="margin:0 0 6px;font-size:22px;font-weight:bold;color:#18181b;">{{ $total }}</p>
                                    @foreach ($discounts as $discount)
                                        <p style="{{ $small }}">{{ __(':name: :amount off', ['name' => $discount['name'], 'amount' => $discount['amount']]) }}</p>
                                    @endforeach
                                    @if ($details['tax_cents'] > 0)
                                        <p style="{{ $small }}">{{ __('Tax included') }}</p>
                                    @endif
                                    <p style="margin:12px 0 0;padding:12px;background:#f4f4f5;border-radius:8px;font-size:15px;color:#18181b;">{{ __('Deposit due on acceptance: :amount (:percent% of the price).', ['amount' => $deposit, 'percent' => $details['deposit_percent']]) }}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px;font-size:13px;line-height:1.6;color:#3f3f46;">
                        <p style="margin:8px 0 12px;font-size:17px;font-weight:bold;color:#18181b;">{{ $wording->title }}</p>
@foreach ($read['intro'] as $block)
@include('mail.partials.agreement-block', ['block' => $block])
@endforeach
@foreach ($read['sections'] as $section)
<p style="margin:20px 0 6px;font-size:14px;font-weight:bold;color:#18181b;">{{ $section['number'] }}. {{ $section['title'] }}</p>
@foreach ($section['blocks'] as $block)
@include('mail.partials.agreement-block', ['block' => $block])
@endforeach
@endforeach
                    </td>
                </tr>

                <tr>
                    <td style="padding:8px 28px 28px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e4e4e7;">
                            <tr>
                                <td style="padding-top:16px;">
                                    <p style="{{ $heading }}">{{ __('Accepted and signed') }}</p>
                                    <p style="{{ $text }}">{{ __('Signed electronically by :name on :when, version :version of the wording. Presented by :salesperson.', ['name' => $agreement->signer_name, 'when' => $signedOn, 'version' => $wording->version, 'salesperson' => $salespersonName]) }}</p>
                                    <p style="{{ $small }}">{{ __('Keep this email as your copy of the agreement.') }}</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
            <p style="margin:16px 0 0;font-size:12px;color:#71717a;">{{ $company }}</p>
        </td>
    </tr>
</table>
</body>
</html>

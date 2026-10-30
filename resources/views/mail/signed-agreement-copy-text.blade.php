{{-- The customer's copy of their signed agreement as plain text (slice 36, step 36a). --}}
{!! $company !!}

{!! __('Hello :name,', ['name' => $agreement->signer_name]) !!}

{!! __('Thank you for choosing :company. Here is a copy of the agreement you signed on :when.', ['company' => config('mail.customers.from_name'), 'when' => $signedOn]) !!}

{!! __('If you have a question, reply to this email to reach :name.', ['name' => $salespersonName]) !!}

{!! __('Customer') !!}: {!! $details['customer'] ?? '—' !!}
{!! __('Property') !!}: {!! $details['property'] ?? '—' !!}

{!! __(':type estimate', ['type' => $details['estimate_type']]) !!}: {!! $details['option']['name'] !!}
@if ($details['option']['description'])
{!! $details['option']['description'] !!}
@endif
@if ($details['areas'] !== [])

{!! __('Rooms and areas') !!}: {!! implode(', ', $details['areas']) !!}
@endif
@if ($details['line_items'] !== [])

{!! __('Details') !!}
@foreach ($details['line_items'] as $item)
- {!! $item['name'] !!}
@if ($item['description'])
  {!! str_replace("\n", "\n  ", $item['description']) !!}
@endif
@endforeach
@endif

{!! __('Price') !!}: {!! $total !!}
@foreach ($discounts as $discount)
{!! __(':name: :amount off', ['name' => $discount['name'], 'amount' => $discount['amount']]) !!}
@endforeach
@if ($details['tax_cents'] > 0)
{!! __('Tax included') !!}
@endif
{!! __('Deposit due on acceptance: :amount (:percent% of the price).', ['amount' => $deposit, 'percent' => $details['deposit_percent']]) !!}

{!! $wording->title !!}

{!! $wording->body !!}

{!! __('Accepted and signed') !!}
{!! __('Signed electronically by :name on :when, version :version of the wording. Presented by :salesperson.', ['name' => $agreement->signer_name, 'when' => $signedOn, 'version' => $wording->version, 'salesperson' => $salespersonName]) !!}
{!! __('Keep this email as your copy of the agreement.') !!}

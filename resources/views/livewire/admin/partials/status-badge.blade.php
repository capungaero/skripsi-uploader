@php
    $colors = [
        'checking' => 'bg-amber-100 text-amber-800',
        'ai_error' => 'bg-orange-100 text-orange-800',
        'ai_rejected' => 'bg-red-100 text-red-700',
        'admin_rejected' => 'bg-red-100 text-red-700',
        'ai_accepted' => 'bg-blue-100 text-blue-800',
        'approved' => 'bg-brand-100 text-brand-800',
        'deposited' => 'bg-brand-700 text-white',
        'deposit_failed' => 'bg-orange-100 text-orange-800',
    ];
@endphp
<span class="inline-block whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {{ $colors[$submission->status] ?? 'bg-slate-100' }}">{{ $submission->adminStatusLabel() }}</span>

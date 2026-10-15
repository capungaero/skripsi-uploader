@php
    $colors = [
        'checking' => 'bg-amber-100 text-amber-800',
        'ai_error' => 'bg-orange-100 text-orange-800',
        'ai_rejected' => 'bg-accent-100 text-accent-600',
        'admin_rejected' => 'bg-accent-100 text-accent-600',
        'ai_accepted' => 'bg-brand-50 text-brand-600 ring-1 ring-brand-200',
        'approved' => 'bg-emerald-50 text-emerald-700',
        'deposited' => 'bg-brand-600 text-white',
        'deposit_failed' => 'bg-orange-100 text-orange-800',
    ];
@endphp
<span class="inline-block whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium {{ $colors[$submission->status] ?? 'bg-slate-100' }}">{{ $submission->adminStatusLabel() }}</span>@if ($submission->review_path)<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800" title="PDF tersimpan untuk evaluasi">PDF</span>@endif

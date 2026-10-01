@extends('museum.admin.layout')
@section('admin_title', 'داشبورد')
@section('admin')
<h1>داشبورد کیفیت داده</h1>
@php $L = ['entities' => 'کل Entityها', 'published_entities' => 'منتشرشده', 'facts' => 'کل Factها', 'verified_facts' => 'Factهای تأییدشده', 'unverified_facts' => 'Factهای تأییدنشده', 'sources' => 'منابع', 'words' => 'واژه‌ها', 'sentences' => 'جمله‌ها', 'audio_recordings' => 'ضبط‌های صوتی', 'places' => 'مکان‌ها', 'interviews' => 'مصاحبه‌ها', 'photos' => 'عکس‌ها', 'documents' => 'اسناد',
  'missing_source' => 'بدون منبع', 'possible_duplicates' => 'تکراری احتمالی', 'conflicting_facts' => 'اختلاف منابع', 'low_confidence' => 'اطمینان پایین', 'needs_review' => 'نیازمند بررسی', 'media_unknown_license' => 'رسانه با پروانه نامعلوم', 'entities_without_facts' => 'Entity بدون Fact']; @endphp
<div class="kpis">@foreach ($q['totals'] as $k => $n)<div class="kpi"><div class="n">{{ number_format($n) }}</div><div class="muted">{{ $L[$k] ?? $k }}</div></div>@endforeach</div>
<h2 class="sec" style="margin-top:24px">مسائل کیفی</h2>
<div class="kpis">@foreach ($q['issues'] as $k => $n)<div @class(['kpi', 'bad' => $n > 0])><div class="n">{{ number_format($n) }}</div><div class="muted">{{ $L[$k] ?? $k }}</div></div>@endforeach</div>
<div class="grid wide" style="margin-top:20px">
  <div class="card"><h3>Factها بر اساس وضعیت</h3><ul class="list">@foreach ($q['facts_by_status'] as $s => $n)<li>@include('museum.partials.status', ['status' => $s])<span>{{ number_format($n) }}</span></li>@endforeach</ul></div>
  <div class="card"><h3>Entityها بر اساس نوع</h3><ul class="list">@foreach ($q['entities_by_type'] as $t => $n)<li><span>{{ $t }}</span><span>{{ number_format($n) }}</span></li>@endforeach</ul></div>
  <div class="card"><h3>منابع بر اساس سطح اعتبار</h3><ul class="list">@foreach ($q['sources_by_tier'] as $t => $n)<li><span>سطح {{ $t }}</span><span>{{ $n }}</span></li>@endforeach</ul></div>
</div>
<h1 style="margin-top:30px">داشبورد Big Data</h1>
<div class="kpis">
  @foreach (['documents_crawled' => 'اسناد دریافت‌شده', 'pages_processed' => 'صفحات پردازش‌شده', 'text_chunks' => 'قطعه‌های متن', 'embeddings' => 'Embeddingها', 'entities_extracted' => 'Entity استخراج‌شده', 'relationships' => 'روابط گراف', 'words' => 'واژه‌ها', 'locations' => 'مکان‌های دارای مختصات', 'media' => 'رسانه‌ها', 'failed_jobs' => 'Jobهای ناموفق'] as $k => $label)
    <div @class(['kpi', 'bad' => $k === 'failed_jobs' && $b[$k] > 0])><div class="n">{{ number_format($b[$k]) }}</div><div class="muted">{{ $label }}</div></div>
  @endforeach
  <div class="kpi"><div class="n">{{ number_format(($b['storage_bytes']['raw'] + $b['storage_bytes']['media']) / 1048576, 1) }} MB</div><div class="muted">فضای ذخیره‌سازی (خام + رسانه)</div></div>
</div>
<div class="grid wide" style="margin-top:20px">
  <div class="card"><h3>صف پردازش</h3>@forelse ($b['processing_queue'] as $queue => $n)<div>{{ $queue }}: {{ $n }}</div>@empty<p class="muted">صف خالی است.</p>@endforelse</div>
  <div class="card"><h3>Candidateهای استخراج</h3>@forelse ($b['candidates_by_status'] as $s => $n)<div>{{ $s }}: {{ $n }}</div>@empty<p class="muted">—</p>@endforelse</div>
  <div class="card"><h3>کارهای AI</h3>@forelse ($b['ai_jobs'] as $s => $n)<div>{{ $s }}: {{ $n }}</div>@empty<p class="muted">—</p>@endforelse<div class="muted">توکن: {{ number_format($b['ai_tokens']['input']) }} ورودی / {{ number_format($b['ai_tokens']['output']) }} خروجی</div></div>
</div>
<h2 class="sec" style="margin-top:24px">آخرین Importها</h2>
<table class="data"><tr><th>Importer</th><th>وضعیت</th><th>Entity جدید</th><th>Fact</th><th>خطا</th><th>زمان</th></tr>
@foreach ($imports as $i)<tr><td><a href="{{ route('museum.admin.imports.show', $i) }}">{{ $i->importer }}</a></td><td>{{ $i->status }}</td><td>{{ $i->entities_created }}</td><td>{{ $i->facts_extracted }}</td><td>{{ $i->errors }}</td><td class="ltr">{{ $i->started_at }}</td></tr>@endforeach</table>
@endsection

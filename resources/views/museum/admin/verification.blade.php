@extends('museum.admin.layout')
@section('admin_title', 'صف تأیید')
@section('admin')
<h1>صف تأیید ادعاها</h1>
<div class="tabs">@foreach (['ai_extracted' => 'استخراج AI', 'unverified' => 'تأییدنشده', 'source_verified' => 'تأیید با منبع', 'community_verified' => 'تأیید جامعه'] as $k => $label)<a href="{{ route('museum.admin.verification', ['status' => $k]) }}" @class(['on' => $status === $k])>{{ $label }}</a>@endforeach
<a href="{{ route('museum.admin.verification', ['status' => $status, 'low' => 1]) }}">فقط اطمینان پایین</a></div>
<p class="muted">ادعا را فقط وقتی تأیید کنید که متن منبع (Extract) دقیقاً همان را بگوید.</p>
@forelse ($facts as $f)
  <div class="card" style="margin-bottom:12px">
    <h3><a href="{{ route('museum.admin.entities.edit', $f->entity) }}">{{ $f->entity->displayName() }}</a> <span class="pill">{{ $f->entity->type->name_fa }}</span> — {{ $f->property->label_fa }}: <span @class(['unknown' => $f->is_unknown])>{{ $f->displayValue() }}</span></h3>
    <div class="src">اطمینان: {{ $f->confidence_score !== null ? round($f->confidence_score * 100).'٪' : '—' }} · روش: {{ $f->extraction_method }} @if($f->conflict_id)· <span class="pill red">اختلاف</span>@endif</div>
    @foreach ($f->sources as $s)
      <div style="margin-top:8px"><strong>{{ $s->source->citationLabel() }}</strong>@if($s->page_number) ، ص {{ $s->page_number }}@endif @if($s->locator)<span class="ltr src"> {{ $s->locator }}</span>@endif
        @if ($s->extract)<div class="quote">{{ \Illuminate\Support\Str::limit($s->extract->original_text, 1200) }}</div>@endif</div>
    @endforeach
    <form method="post" action="{{ route('museum.admin.facts.status', $f) }}" style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      @csrf
      <input type="text" name="notes" placeholder="یادداشت بررسی" style="max-width:280px">
      <button class="btn small alt" name="status" value="source_verified">تأیید با منبع</button>
      <button class="btn small" name="status" value="expert_verified">تأیید کارشناس</button>
      <button class="btn small ghost" name="status" value="community_verified">تأیید جامعه</button>
      <button class="btn small danger" name="status" value="rejected">رد (حفظ می‌شود)</button>
    </form>
  </div>
@empty
  <div class="empty">صف خالی است.</div>
@endforelse
{{ $facts->links('museum.partials.pager') }}
@endsection

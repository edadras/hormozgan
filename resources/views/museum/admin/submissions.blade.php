@extends('museum.admin.layout')
@section('admin_title', 'مشارکت‌ها')
@section('admin')
<h1>مشارکت‌های مردمی</h1>
<div class="tabs">@foreach (\App\Museum\Enums\SubmissionStatus::cases() as $st)<a href="?status={{ $st->value }}" @class(['on' => $status === $st->value])>{{ $st->label() }}</a>@endforeach</div>
@forelse ($subs as $s)
  <div class="card" style="margin-bottom:12px">
    <span class="pill">{{ $s->submission_type }}</span> <strong>{{ $s->title }}</strong> <span class="src ltr">{{ $s->uuid }}</span>
    <pre class="json">{{ json_encode($s->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    <div class="src">منطقه: {{ $s->place?->displayName() ?? $s->place_text }} · گویش: {{ $s->dialect_text }} · رضایت انتشار: {{ $s->consent_publish ? 'دارد' : 'ندارد' }} · اثر خود: {{ $s->is_own_work ? 'بله' : 'خیر' }} · ارسال‌کننده: {{ $s->submitter_name ?? 'ناشناس' }}</div>
    @if ($s->ai_check)<details><summary>بررسی خودکار</summary><pre class="json">{{ json_encode($s->ai_check, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>@endif
    @php $next = \App\Museum\Enums\SubmissionStatus::from($s->status)->next(); @endphp
    <form method="post" action="{{ route('museum.admin.submissions.transition', $s) }}" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
      @csrf
      <input type="text" name="place_slug" placeholder="slug مکان (اختیاری)" style="max-width:200px">
      <input type="text" name="notes" placeholder="یادداشت" style="max-width:260px">
      @foreach ($next as $to)<button class="btn small @if($to->value === 'rejected') danger @endif" name="to" value="{{ $to->value }}">{{ $to->label() }}</button>@endforeach
    </form>
  </div>
@empty
  <div class="empty">موردی نیست.</div>
@endforelse
{{ $subs->links('museum.partials.pager') }}
@endsection

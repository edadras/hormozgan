@extends('museum.admin.layout')
@section('admin_title', 'اختلاف منابع')
@section('admin')
<h1>اختلاف منابع</h1>
<div class="tabs"><a href="?status=open" @class(['on' => request('status', 'open') === 'open'])>باز</a><a href="?status=resolved" @class(['on' => request('status') === 'resolved'])>بررسی‌شده</a></div>
@forelse ($conflicts as $c)
  <form method="post" action="{{ route('museum.admin.conflicts.resolve', $c) }}" class="card" style="margin-bottom:12px">
    @csrf
    <h3>{{ $c->entity->displayName() }} — {{ $c->property->label_fa }}</h3>
    @foreach ($c->facts as $f)
      <label class="check"><input type="radio" name="preferred_fact_id" value="{{ $f->id }}" @checked($c->preferred_fact_id === $f->id)>
        <span><strong>{{ $f->displayValue() }}</strong> @include('museum.partials.status', ['status' => $f->verification_status])<br>
        <span class="src">@foreach ($f->sources as $s){{ $s->source->citationLabel() }}@if($s->page_number) ، ص {{ $s->page_number }}@endif (اعتبار {{ $s->source->reliability_tier }}/۵)<br>@endforeach</span></span></label>
    @endforeach
    @if ($c->status === 'open')
      <label>یادداشت کارشناس (الزامی)</label><textarea name="note" required></textarea>
      <label class="check"><input type="checkbox" name="reject_others" value="1"> بقیه مقادیر رد شوند (حذف نمی‌شوند)</label>
      <button class="btn small alt" name="resolution" value="preferred_selected">ترجیح مقدار انتخاب‌شده</button>
      <button class="btn small ghost" name="resolution" value="both_retained">حفظ هر دو (اختلاف مستند)</button>
    @else
      <p class="muted">{{ $c->resolution }} — {{ $c->resolution_note }}</p>
    @endif
  </form>
@empty
  <div class="empty">اختلافی وجود ندارد.</div>
@endforelse
{{ $conflicts->links('museum.partials.pager') }}
@endsection

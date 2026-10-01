@extends('museum.layout')
@section('title', $q ? 'جستجو: '.$q : 'جستجو')
@section('content')
<h1>جستجو</h1>
<form class="big-search" action="{{ route('museum.search') }}" style="margin-bottom:18px">
  <input type="search" name="q" value="{{ $q }}" placeholder="فارسی، واژه محلی، انگلیسی، املای دیگر یا نام تاریخی" aria-label="جستجو">
  <button>جستجو</button>
</form>
@if ($q !== '')
  <p class="muted">{{ number_format($res['total']) }} نتیجه برای «{{ $q }}»</p>
  @forelse ($res['hits'] as $h)
    <div class="card" style="margin-bottom:10px">
      <span class="pill sea">{{ __('museum.types.'.$h['doc']->doc_type) !== 'museum.types.'.$h['doc']->doc_type ? __('museum.types.'.$h['doc']->doc_type) : $h['doc']->doc_type }}</span>
      <h3 style="margin-top:6px"><a href="{{ url($h['doc']->url) }}">{{ $h['doc']->title }}</a></h3>
      <div class="muted">{{ \Illuminate\Support\Str::limit($h['doc']->body, 220) }}</div>
    </div>
  @empty
    @include('museum.partials.empty', ['title' => 'نتیجه‌ای یافت نشد.', 'text' => 'شاید این موضوع هنوز در موزه ثبت نشده باشد. اگر درباره آن اطلاعاتی دارید'])
  @endforelse
  @if ($res['total'] > $page * 30)<div class="pager"><a class="btn ghost small" href="{{ route('museum.search', ['q' => $q, 'page' => $page + 1]) }}">نتایج بیشتر</a></div>@endif
@endif
@endsection

@extends('museum.layout')
@section('title', $title)
@section('content')
<h1>{{ $icon }} {{ $title }}</h1>
<p class="muted">{{ $intro }}</p>
<div class="tabs">
  <a href="{{ route('museum.section', $section) }}" @class(['on' => !$activeType])>همه</a>
  @foreach ($typeList as $t)
    @if ($t['n'] > 0)<a href="{{ route('museum.section', [$section, 'type' => $t['key']]) }}" @class(['on' => $activeType === $t['key']])>{{ $t['name'] }} ({{ number_format($t['n']) }})</a>@endif
  @endforeach
  @if ($section === 'sea')<a href="{{ route('museum.lenj') }}">لنج تعاملی</a>@endif
</div>
<form method="get" style="max-width:420px;margin-bottom:16px">
  @if ($activeType)<input type="hidden" name="type" value="{{ $activeType }}">@endif
  <input type="text" name="q" value="{{ request('q') }}" placeholder="فیلتر بر اساس نام…" aria-label="فیلتر">
</form>
@if ($entities->isEmpty())
  @include('museum.partials.empty')
@else
  <div class="grid">
    @foreach ($entities as $e)
      @include('museum.partials.entity-card', ['e' => app(\App\Museum\Support\EntityPresenter::class)->summary($e)])
    @endforeach
  </div>
  <div class="pager">{{ $entities->links('museum.partials.pager') }}</div>
@endif
@endsection

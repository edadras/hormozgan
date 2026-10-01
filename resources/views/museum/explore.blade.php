@extends('museum.layout')
@section('title', 'کشف هرمزگان')
@section('content')
<h1>کشف هرمزگان</h1>
<p class="muted">هر بار مجموعه‌ای تازه از گنجینه موزه. <a href="{{ route('museum.explore') }}">دوباره ↻</a></p>
@php $labels = ['word' => 'یک واژه', 'food' => 'یک غذای محلی', 'place' => 'یک مکان', 'historical_photo' => 'یک عکس تاریخی', 'proverb' => 'یک ضرب‌المثل', 'person' => 'یک شخصیت', 'plant' => 'یک گیاه', 'music' => 'یک قطعه موسیقی']; @endphp
<div class="grid wide">
  @foreach ($labels as $k => $label)
    <div class="card">
      <div class="muted">{{ $label }}</div>
      @if (!empty($items[$k]))
        @if ($k === 'historical_photo')
          <img loading="lazy" src="{{ $items[$k]['thumb'] ?? $items[$k]['url'] }}" alt="{{ $items[$k]['title'] }}"><div>{{ $items[$k]['title'] }} ({{ $items[$k]['year'] }})</div>
        @else
          <h3><a href="{{ $items[$k]['url'] }}">{{ $items[$k]['name'] }}</a></h3>
          <span class="pill sea">{{ $items[$k]['type_label'] }}</span>
        @endif
      @else
        <p class="muted" style="margin:.5em 0 0">هنوز موردی تأییدشده منتشر نشده. <a href="{{ route('museum.contribute') }}">کمک کنید</a></p>
      @endif
    </div>
  @endforeach
</div>
@endsection

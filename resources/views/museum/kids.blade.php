@extends('museum.layout')
@section('title', 'هرمزگان برای کودکان')
@section('body_class', 'kids')
@section('content')
<h1 style="text-align:center">🐠 هرمزگان برای کودکان</h1>
<p style="text-align:center">بیا با دریا، نخل، لنج و آدم‌های هرمزگان آشنا شویم!</p>
<div class="grid">
  @foreach ([['🗺️', 'نقشه', route('museum.home').'#map', '#0e5e6f'], ['🗣️', 'کلمه‌ها', route('museum.section', ['language', 'type' => 'word']), '#2a9d8f'], ['🍲', 'غذاها', route('museum.section', 'food'), '#b8862b'],
            ['👗', 'لباس‌ها', route('museum.section', ['culture', 'type' => 'clothing_item']), '#9c3b28'], ['🎲', 'بازی‌ها', route('museum.section', ['culture', 'type' => 'game']), '#4f6d3a'], ['⛵', 'لنج', route('museum.lenj'), '#0a3742'],
            ['🎵', 'صداها', route('museum.section', ['culture', 'type' => 'music_instrument']), '#6b4a10'], ['📖', 'داستان‌ها', route('museum.section', ['culture', 'type' => 'story']), '#2a9d8f']] as [$ico, $label, $href, $bg])
    <a class="tile card" style="background:{{ $bg }}" href="{{ $href }}"><span class="ico">{{ $ico }}</span>{{ $label }}</a>
  @endforeach
</div>
<section class="section card" style="text-align:center">
  <h2>امروز یاد بگیر!</h2>
  @if (!empty($items['word']))<p style="font-size:1.4rem">یک کلمه: <a href="{{ $items['word']['url'] }}">{{ $items['word']['name'] }}</a></p>
  @elseif (!empty($items['place']))<p style="font-size:1.4rem">یک جا: <a href="{{ $items['place']['url'] }}">{{ $items['place']['name'] }}</a></p>
  @else<p>به‌زودی بازی، پازل و مسابقه با داده‌های تأییدشده اضافه می‌شود.</p>@endif
  <div id="kids-quiz" data-api="{{ url('/api/museum/places?type=county&per_page=100') }}"></div>
</section>
@endsection

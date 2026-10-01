@extends('museum.layout')
@section('title', 'خانه')
@push('head')<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">@endpush
@section('hero')
<section class="hero">
  <div class="wrap">
    <h1>موزه دیجیتال هرمزگان</h1>
    <p class="lead">زبان، تاریخ، مردم، دریا و فرهنگ هرمزگان</p>
    <form class="big-search" action="{{ route('museum.search') }}" role="search">
      <input type="search" name="q" placeholder="یک مکان، واژه محلی، نام قدیمی یا غذا را جستجو کنید…" aria-label="جستجو در موزه" autofocus>
      <button type="submit">جستجو</button>
    </form>
    <p class="hint">یا <a href="{{ route('museum.ask') }}">از موزه بپرسید</a> — پاسخ‌ها فقط از پایگاه دانش موزه و همراه با منبع.</p>
  </div>
  <svg class="waves" viewBox="0 0 1200 70" preserveAspectRatio="none" aria-hidden="true"><path fill="currentColor" d="M0 40c150-30 300 30 450 10s300-40 450-10 225 20 300 0v30H0z"/></svg>
</section>
@endsection
@section('content')
<section class="section">
  <h2>نقشه هرمزگان</h2>
  <div id="map" data-geojson="{{ url('/api/museum/map') }}" data-region="{{ url('/api/museum/map/regions') }}"></div>
  <div id="region-panel" class="card" style="margin-top:12px" hidden></div>
</section>

<section class="section">
  <h2>کشف هرمزگان</h2>
  <div class="grid">
    @foreach ($sections as $key => [$title, $intro, $types, $icon])
      <a class="card door" href="{{ $key === 'language' ? route('museum.language') : ($key === 'history' ? route('museum.history') : route('museum.section', $key)) }}">
        <span class="ico" aria-hidden="true">{{ $icon }}</span>
        <span class="count">{{ number_format($counts[$key] ?? 0) }}</span>
        <h3>{{ $title }}</h3>
        <div class="meta">{{ $intro }}</div>
      </a>
    @endforeach
  </div>
</section>

<section class="section">
  <h2>موزه در یک نگاه</h2>
  <div class="grid">
    <div class="card"><div class="stat">{{ number_format($stats['entities']) }}</div><div class="muted">مدخل منتشرشده</div></div>
    <div class="card"><div class="stat">{{ number_format($stats['public_facts']) }}</div><div class="muted">ادعای مستند با منبع</div></div>
    <div class="card"><div class="stat">{{ number_format($stats['sources']) }}</div><div class="muted">منبع ثبت‌شده</div></div>
    <div class="card"><div class="stat">{{ number_format($stats['words']) }}</div><div class="muted">واژه محلی منتشرشده</div></div>
  </div>
  <p class="muted" style="margin-top:10px">موزه از ابتدای راه است: هر عدد بالا فقط شامل داده‌های منتشرشده و قابل ردیابی به منبع است.</p>
</section>
@endsection
@push('scripts')<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>@endpush

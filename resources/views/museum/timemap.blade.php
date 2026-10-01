@extends('museum.layout')
@section('title', 'هرمزگان در گذر زمان')
@push('head')<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">@endpush
@section('content')
<h1>هرمزگان در گذر زمان</h1>
<p class="muted">لغزنده زمان را حرکت دهید. فقط عوارضی نمایش داده می‌شوند که برای آن سال داده معتبر و مستند (نام‌های تاریخی، سواحل، بنادر، بازارها، راه‌ها) ثبت شده باشد؛ جاهای خالی یعنی هنوز داده‌ای تأیید نشده است.</p>
<div id="timemap" data-api="{{ url('/api/museum/history/layers') }}">
  <input class="slider" type="range" min="0" max="5" step="1" value="5" list="years" aria-label="سال">
  <div class="years"><span>۱۸۰۰</span><span>۱۸۵۰</span><span>۱۹۰۰</span><span>۱۹۵۰</span><span>۲۰۰۰</span><span>امروز</span></div>
  <p><strong id="tm-year"></strong> · <span id="tm-count" class="muted"></span></p>
  <div class="map" id="tm-map"></div>
</div>
@endsection
@push('scripts')<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>@endpush

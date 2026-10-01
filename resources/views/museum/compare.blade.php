@extends('museum.layout')
@section('title', 'مقایسه گویش‌ها')
@push('head')<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">@endpush
@section('content')
<h1>مقایسه گویش‌ها</h1>
<p class="muted">یک مفهوم را انتخاب کنید تا شکل‌های ثبت‌شده آن در مناطق مختلف را ببینید — فقط بر اساس داده‌های مستند.</p>
<form method="get" style="max-width:420px">
  <select name="concept" onchange="this.form.submit()" aria-label="مفهوم">
    <option value="">— انتخاب مفهوم —</option>
    @foreach ($concepts as $c)<option value="{{ $c->key }}" @selected($concept?->key === $c->key)>{{ $c->gloss_fa }} ({{ $c->gloss_en }})</option>@endforeach
  </select>
</form>
@if ($concepts->isEmpty())<div style="margin-top:16px">@include('museum.partials.empty', ['title' => 'هنوز مفهومی با واژه‌های مستند ثبت نشده است.'])</div>@endif
@if ($concept)
  <div id="compare" data-api="{{ url('/api/museum/dialects/compare?concept='.$concept->key) }}" style="margin-top:18px">
    <div class="map" id="compare-map"></div>
    <div class="scroll-x" style="margin-top:14px"><table class="data" id="compare-table"><tr><th>واژه</th><th>تلفظ</th><th>گویش</th><th>مکان</th><th>صدا</th><th>منبع</th></tr></table></div>
  </div>
@endif
@endsection
@push('scripts')<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>@endpush

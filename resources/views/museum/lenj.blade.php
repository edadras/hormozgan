@extends('museum.layout')
@section('title', 'لنج تعاملی')
@section('content')
<h1>⛵ لنج تعاملی</h1>
<p class="muted">مهارت ساخت و دریانوردی لنج ایرانی در فهرست میراث ناملموس یونسکو ثبت شده است. روی هر بخش کلیک کنید تا نام فارسی، نام محلی، تلفظ، کاربرد و منبع آن را ببینید.</p>
@if (!$diagram || $parts->isEmpty())
  @include('museum.partials.empty', ['title' => 'نمودار تعاملی لنج هنوز با اجزای تأییدشده منتشر نشده است.', 'text' => 'اجزای لنج و اصطلاحات دریایی باید از منابعی مانند پرونده یونسکو و مصاحبه با لنج‌سازان استخراج و تأیید شوند؛ پس از تأیید، در این نمودار نمایش داده می‌شوند.'])
@else
  <div class="layout-2 lenj">
    <div class="card">
      <svg viewBox="{{ $diagram->viewbox ?? '0 0 1000 600' }}" role="img" aria-label="{{ $diagram->title }}">
        @if ($diagram->image?->isPubliclyServable())<image href="{{ $diagram->image->publicUrl() }}" width="100%" height="100%"/>@endif
        @foreach ($parts as $p)
          @if ($p['shape'] === 'circle') @php [$cx,$cy,$r] = array_map('floatval', explode(',', $p['coords'])); @endphp
            <circle class="hot" data-part="{{ $p['id'] }}" cx="{{ $cx }}" cy="{{ $cy }}" r="{{ $r }}" tabindex="0"><title>{{ $p['part']['name'] }}</title></circle>
          @else
            <polygon class="hot" data-part="{{ $p['id'] }}" points="{{ $p['coords'] }}" tabindex="0"><title>{{ $p['part']['name'] }}</title></polygon>
          @endif
        @endforeach
      </svg>
    </div>
    <div>
      @foreach ($parts as $p)
        <div class="card part-info" id="part-{{ $p['id'] }}" hidden>
          <h2>{{ $p['part']['name'] }}</h2>
          @if ($p['part']['name_local'])<p>نام محلی: <strong>{{ $p['part']['name_local'] }}</strong></p>@endif
          @foreach ($p['part']['facts'] as $facts) @foreach ($facts as $f)
            <p><span class="muted">{{ $f['label'] }}:</span> {{ $f['value'] }} <a class="src" href="{{ $f['provenance_url'] }}">منبع</a></p>
          @endforeach @endforeach
          <a href="{{ $p['part']['url'] }}">صفحه کامل</a>
        </div>
      @endforeach
      <p class="muted" id="part-hint">یک بخش از لنج را انتخاب کنید.</p>
    </div>
  </div>
@endif
@endsection

@extends('museum.layout')
@section('title', 'منبع: '.$p['fact']['label'].' — '.$p['entity']['name'])
@section('content')
<div class="crumbs"><span><a href="{{ $p['entity']['url'] }}">{{ $p['entity']['name'] }}</a></span></div>
<h1>{{ $p['fact']['label'] }}: <span @class(['unknown' => $p['fact']['is_unknown']])>{{ $p['fact']['value'] }}</span></h1>
<p>@include('museum.partials.status', ['status' => $p['fact']['verification_status']])
  @if ($p['fact']['confidence'] !== null)<span class="pill">اطمینان استخراج: {{ round($p['fact']['confidence'] * 100) }}٪</span>@endif
  @if ($p['fact']['disputed'])<span class="pill red">منابع در این مورد اختلاف دارند</span>@endif</p>

<section class="section">
  <h2 class="sec">زنجیره منبع</h2>
  <p class="muted">ادعا ← منبع ← صفحه/مکان در منبع ← متن اصلی ← فایل خام</p>
  @foreach ($p['evidence'] as $ev)
    <div class="card" style="margin-bottom:12px">
      <h3><a href="{{ $ev['source']['page_url'] }}">{{ $ev['source']['citation'] }}</a></h3>
      <div class="meta">
        نوع: {{ $ev['source']['type'] }} · پروانه: {{ $ev['source']['license'] }} · سطح اعتبار منبع: {{ $ev['source']['reliability_tier'] }} از ۵
        @if ($ev['page']) · صفحه {{ $ev['page'] }}@endif
        @if ($ev['locator']) · <span class="ltr">{{ $ev['locator'] }}</span>@endif
        @if ($ev['source']['url']) · <a href="{{ $ev['source']['url'] }}" rel="noopener" target="_blank">نسخه آنلاین</a>@endif
      </div>
      @if ($ev['quote'])<p class="quote">{{ $ev['quote'] }}</p>@endif
      @if ($ev['extract'])
        <details><summary>متن اصلی استخراج‌شده ({{ $ev['extract']['extracted_by'] }})</summary>
          @if (str_starts_with(trim($ev['extract']['original_text']), '{'))<pre class="json">{{ json_encode(json_decode($ev['extract']['original_text']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
          @else<p class="quote">{{ $ev['extract']['original_text'] }}</p>@endif
          @if ($ev['extract']['raw_document'])<p class="src">فایل خام: <span class="ltr">{{ $ev['extract']['raw_document']['url'] }}</span> · SHA-256 <span class="ltr">{{ substr($ev['extract']['raw_document']['sha256'], 0, 16) }}…</span> · دریافت {{ $ev['extract']['raw_document']['fetched_at'] }}</p>@endif
        </details>
      @endif
    </div>
  @endforeach
</section>

@if ($p['conflict'])
<section class="section">
  <h2 class="sec">اختلاف منابع</h2>
  <p>وضعیت: {{ $p['conflict']['status'] === 'open' ? 'باز — در انتظار تصمیم کارشناس' : 'بررسی‌شده' }} @if($p['conflict']['note'])— {{ $p['conflict']['note'] }}@endif</p>
  <ul class="list">@foreach ($p['conflict']['alternatives'] as $alt)
    <li><span>{{ $alt['value'] }} <span class="muted">({{ $alt['sources']->implode('؛ ') }})</span></span> @include('museum.partials.status', ['status' => $alt['status']])</li>
  @endforeach</ul>
</section>
@endif

<section class="section">
  <h2 class="sec">تاریخچه نسخه‌ها</h2>
  <div class="scroll-x"><table class="data"><tr><th>#</th><th>تغییر</th><th>مقدار قبلی</th><th>مقدار جدید</th><th>وضعیت</th><th>دلیل</th><th>زمان</th></tr>
  @foreach ($p['revisions'] as $r)
    <tr><td>{{ $r['revision'] }}</td><td>{{ $r['change'] }}</td><td class="ltr">{{ $r['previous'] ? json_encode($r['previous'], JSON_UNESCAPED_UNICODE) : '—' }}</td>
      <td class="ltr">{{ $r['new'] ? \Illuminate\Support\Str::limit(json_encode($r['new'], JSON_UNESCAPED_UNICODE), 80) : '—' }}</td>
      <td>{{ $r['previous_status'] }} {{ $r['new_status'] ? '→ '.$r['new_status'] : '' }}</td><td>{{ $r['reason'] }}</td><td class="ltr">{{ $r['at'] }}</td></tr>
  @endforeach</table></div>
</section>
@endsection

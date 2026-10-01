@extends('museum.layout')
@section('title', $d['name'])
@section('description', $d['type_label'].' — '.$d['name'].' در موزه دیجیتال هرمزگان')
@push('head')@if ($d['latitude'])<link rel="stylesheet" href="{{ asset('museum-assets/vendor/leaflet/leaflet.css') }}">@endif @endpush
@section('content')
@if ($preview)<div class="notice warn">پیش‌نمایش مدیریتی: این مدخل هنوز منتشر نشده است.</div>@endif
@if ($d['breadcrumbs'])
  <div class="crumbs">@foreach ($d['breadcrumbs'] as $b)<span><a href="{{ url('/museum/e/'.$b['slug']) }}">{{ $b['name'] }}</a></span>@endforeach</div>
@endif
<div class="title-row">
  <span class="pill sea">{{ $d['type_label'] }}</span>
  <h1>{{ $d['name'] }}</h1>
</div>
<div class="names">
  @foreach ($d['aliases']->unique('alias')->take(12) as $a)
    @if ($a['alias'] !== $d['name'])<span class="pill" @if(($a['language'] ?? '') === 'en') dir="ltr" @endif>{{ $a['alias'] }}</span>@endif
  @endforeach
</div>
@if ($d['summary'])<p style="font-size:1.08rem">{{ $d['summary'] }}</p>@endif

<div class="layout-2">
<div>
  @if (!empty($d['extension']['meanings']))
    <section class="card section">
      <h2 class="sec">واژه</h2>
      <p><strong style="font-size:1.4rem">{{ $d['extension']['headword'] }}</strong>
        @if ($d['extension']['transcription_fa']) <span class="muted">/{{ $d['extension']['transcription_fa'] }}/</span>@endif
        @if ($d['extension']['ipa']) <span class="muted ltr">[{{ $d['extension']['ipa'] }}]</span>@endif</p>
      <ol>@foreach ($d['extension']['meanings'] as $m)<li>{{ $m['fa'] }} @if($m['en'])<span class="muted ltr">— {{ $m['en'] }}</span>@endif</li>@endforeach</ol>
      @if (count($d['extension']['pronunciations']))
        <h3>تلفظ بومی</h3>
        <ul class="list">@foreach ($d['extension']['pronunciations'] as $p)
          <li><span>{{ $p['speaker'] }} · {{ $p['place'] }} @if($p['gender'])· {{ $p['gender'] === 'female' ? 'زن' : ($p['gender'] === 'male' ? 'مرد' : '') }}@endif @if($p['age_group'])· {{ $p['age_group'] }}@endif</span>
            <audio controls preload="none" src="{{ $p['audio'] }}"></audio></li>@endforeach</ul>
      @else
        <p class="muted">هنوز تلفظی از گویشوران بومی برای این واژه ضبط نشده است. (تلفظ ماشینی/TTS به‌عنوان مدرک پذیرفته نمی‌شود.)</p>
      @endif
    </section>
  @endif
  @if (!empty($d['extension']['text_local']))
    <section class="card section">
      <h2 class="sec">{{ ($d['extension']['kind'] ?? '') === 'idiom' ? 'اصطلاح' : 'ضرب‌المثل' }}</h2>
      <p style="font-size:1.3rem">{{ $d['extension']['text_local'] }}</p>
      <table class="facts">
        @foreach (['transcription_fa' => 'تلفظ', 'literal_meaning' => 'معنی تحت‌اللفظی', 'figurative_meaning' => 'مفهوم', 'usage_context' => 'زمان کاربرد', 'backstory' => 'داستان', 'persian_equivalent' => 'معادل فارسی'] as $k => $label)
          @if (!empty($d['extension'][$k]))<tr><th>{{ $label }}</th><td>{{ $d['extension'][$k] }}</td></tr>@endif
        @endforeach
      </table>
    </section>
  @endif

  <section class="section">
    <h2 class="sec">اطلاعات مستند</h2>
    @php $groupLabels = ['general' => 'کلیات', 'geography' => 'جغرافیا', 'history' => 'تاریخ', 'economy' => 'اقتصاد', 'language' => 'زبان', 'culture' => 'فرهنگ', 'nature' => 'طبیعت', 'people' => 'مردم', 'maritime' => 'دریا', 'archive' => 'آرشیو']; @endphp
    @forelse ($d['facts'] as $group => $facts)
      <h3>{{ $groupLabels[$group] ?? $group }}</h3>
      <table class="facts card" style="padding:0">
        @foreach ($facts as $f)
          <tr @class(['disputed' => $f['disputed']])>
            <th>{{ $f['label'] }}@if (!empty($f['qualifiers']['census_year']))<div class="src">آمار سال {{ $f['qualifiers']['census_year'] }}</div>@endif
              @if ($f['scope_place'])<div class="src">در {{ $f['scope_place'] }}</div>@endif</th>
            <td>
              <div class="val @if($f['is_unknown']) unknown @endif">
                @if ($f['value_entity'])<a href="{{ url('/museum/e/'.$f['value_entity']['slug']) }}">{{ $f['value_entity']['name'] }}</a>@else{{ $f['value'] }}@endif
                @if ($f['unit'] && !$f['is_unknown']) <span class="muted">{{ $f['unit'] }}</span>@endif
              </div>
              <div class="src">
                @include('museum.partials.status', ['status' => $f['verification_status']])
                @if ($f['disputed'])<span class="pill red">منابع اختلاف دارند</span>@endif
                @foreach ($f['sources'] as $s)
                  · <a href="{{ $s['source']['page_url'] }}" title="{{ $s['source']['citation'] }}">{{ $s['source']['short'] }}</a>@if($s['page']) ، ص {{ $s['page'] }}@endif
                @endforeach
                · <a href="{{ $f['provenance_url'] }}">مشاهده منبع</a>
              </div>
            </td>
          </tr>
        @endforeach
      </table>
    @empty
      @include('museum.partials.empty', ['title' => 'هنوز ادعای تأییدشده‌ای برای این مدخل منتشر نشده است.'])
    @endforelse
  </section>

  @if (count($d['historical_names']))
    <section class="section">
      <h2 class="sec">نام‌های تاریخی</h2>
      <div class="scroll-x"><table class="data">
        <tr><th>نام</th><th>دوره</th><th>تلفظ محلی</th><th>معنی / علت نام‌گذاری</th><th>منبع</th></tr>
        @foreach ($d['historical_names'] as $h)
          <tr><td>{{ $h['name'] }}</td><td>{{ $h['period'] }} @if($h['year_from']){{ $h['year_from'] }}@if($h['year_to'] && $h['year_to'] != $h['year_from'])–{{ $h['year_to'] }}@endif @endif</td>
            <td>{{ $h['local_pronunciation'] }}</td><td>{{ $h['meaning'] }} {{ $h['reason'] }}</td>
            <td class="src">@if($h['source'])<a href="{{ $h['source']['page_url'] }}">{{ \Illuminate\Support\Str::limit($h['source']['citation'], 60) }}</a>@if($h['page']) ، ص {{ $h['page'] }}@endif @endif</td></tr>
        @endforeach
      </table></div>
    </section>
  @endif

  @if (count($d['children']))
    <section class="section">
      <h2 class="sec">زیرمجموعه‌ها ({{ count($d['children']) }})</h2>
      <div class="grid">@foreach ($d['children'] as $c) @include('museum.partials.entity-card', ['e' => $c]) @endforeach</div>
    </section>
  @endif

  @if (count($d['mentions']))
    <section class="section">
      <h2 class="sec">در مصاحبه‌ها و اسناد</h2>
      <ul class="list">@foreach ($d['mentions'] as $m)
        <li><span><span class="pill">{{ $m['kind'] === 'interview' ? 'تاریخ شفاهی' : 'سند' }}</span> <a href="{{ $m['url'] }}">{{ $m['title'] }}</a><br><span class="muted">«{{ $m['excerpt'] }}…»</span></span></li>
      @endforeach</ul>
    </section>
  @endif
</div>

<aside>
  @if ($d['latitude'])
    <div class="map small" id="mini-map" data-lat="{{ $d['latitude'] }}" data-lng="{{ $d['longitude'] }}" data-name="{{ $d['name'] }}"></div>
  @endif
  @if (count($d['media']))
    <section class="section"><h2 class="sec">تصاویر و رسانه</h2>
      @foreach ($d['media'] as $m)
        <figure class="card" style="margin:0 0 10px">
          @if ($m['type'] === 'image')<img loading="lazy" src="{{ $m['thumb'] ?? $m['url'] }}" alt="{{ $m['title'] }}">
          @elseif ($m['type'] === 'audio')<audio controls preload="none" src="{{ $m['url'] }}"></audio>
          @elseif ($m['type'] === 'video')<video controls preload="none" src="{{ $m['url'] }}" style="width:100%"></video>@endif
          <figcaption class="muted">{{ $m['title'] }} @if($m['year'])({{ $m['year_precision'] === 'circa' ? 'حدود ' : '' }}{{ $m['year'] }})@endif · {{ $m['creator'] }} · {{ $m['license'] }} @if($m['source'])· {{ $m['source'] }}@endif</figcaption>
        </figure>
      @endforeach
    </section>
  @endif
  @if (count($d['relations']))
    <section class="section card"><h2 class="sec">پیوندها در گراف دانش</h2>
      <ul class="list">@foreach ($d['relations']->take(60) as $r)
        <li><span class="muted">{{ $r['label'] }}</span> <a href="{{ $r['entity']['url'] }}">{{ $r['entity']['name'] }}</a></li>
      @endforeach</ul>
    </section>
  @endif
  <section class="section card">
    <h2 class="sec">اطلاعات بیشتری دارید؟</h2>
    <p class="muted">اگر نام قدیمی، تلفظ، عکس یا خاطره‌ای دربارهٔ «{{ $d['name'] }}» دارید، آن را برای بررسی ارسال کنید.</p>
    <a class="btn alt small" href="{{ route('museum.contribute') }}">ارسال اطلاعات</a>
  </section>
</aside>
</div>
@endsection
@push('scripts')@if ($d['latitude'])<script src="{{ asset('museum-assets/vendor/leaflet/leaflet.js') }}"></script>@endif @endpush

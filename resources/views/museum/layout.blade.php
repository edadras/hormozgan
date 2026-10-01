<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', 'موزه دیجیتال هرمزگان') — موزه دیجیتال هرمزگان</title>
<meta name="description" content="@yield('description', 'زبان، تاریخ، مردم، دریا و فرهنگ هرمزگان — آرشیو مستند و قابل ردیابی به منبع.')">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;800&display=swap">
<link rel="stylesheet" href="{{ asset('museum-assets/museum.css') }}?v=1">
@stack('head')
</head>
<body class="@yield('body_class')">
<a class="skip" href="#main">رفتن به محتوا</a>
<header class="site-head">
  <div class="wrap">
    <a class="brand" href="{{ route('museum.home') }}" aria-label="موزه دیجیتال هرمزگان">
      <svg viewBox="0 0 40 40" aria-hidden="true"><path d="M4 26h32l-5 7H9z" fill="#b8862b"/><path d="M20 4v20M20 5l11 17H20" fill="none" stroke="#f4ede1" stroke-width="2"/><path d="M2 36c6-3 10 3 18 0s12 3 18 0" fill="none" stroke="#2a9d8f" stroke-width="2"/></svg>
      <span>موزه دیجیتال هرمزگان</span>
    </a>
    <nav class="nav" aria-label="بخش‌های موزه">
      @foreach (['language' => 'زبان', 'history' => 'تاریخ', 'sea' => 'دریا', 'food' => 'خوراک', 'people' => 'مردم', 'nature' => 'طبیعت', 'culture' => 'فرهنگ', 'archive' => 'آرشیو', 'places' => 'مکان‌ها'] as $k => $label)
        <a href="{{ $k === 'language' ? route('museum.language') : ($k === 'history' ? route('museum.history') : route('museum.section', $k)) }}" @class(['on' => request()->is('museum/'.$k.'*')])>{{ $label }}</a>
      @endforeach
      <a href="{{ route('museum.explore') }}">کشف</a>
      <a href="{{ route('museum.kids') }}">کودکان</a>
    </nav>
    <form class="head-search" action="{{ route('museum.search') }}" role="search">
      <input type="search" name="q" value="{{ request('q') }}" placeholder="جستجو: مکان، واژه، نام قدیمی…" aria-label="جستجو">
    </form>
  </div>
  <div class="band" role="presentation"></div>
</header>
@yield('hero')
<main id="main"><div class="wrap">
  @if (session('status'))<div class="notice">{{ session('status') }}</div>@endif
  @yield('content')
</div></main>
<footer class="site-foot">
  <div class="band" role="presentation"></div>
  <div class="wrap">
    <div>
      <strong>موزه دیجیتال هرمزگان‌شناسی</strong>
      <p class="principles"><span>دقت</span><span>ردیابی‌پذیری</span><span>حفاظت</span></p>
      <p>هر ادعا در این موزه به منبع آن پیوند دارد. اطلاعات نامعلوم «نامعلوم» نوشته می‌شود و هیچ داده‌ای ساختگی نیست.</p>
    </div>
    <div>
      <a href="{{ route('museum.contribute') }}">شما هم به موزه کمک کنید</a><br>
      <a href="{{ route('museum.ask') }}">از موزه بپرسید</a><br>
      <a href="{{ route('museum.history.map') }}">هرمزگان در گذر زمان</a>
    </div>
    <div>
      <a href="{{ url('/api/museum/knowledge/stats') }}">API</a> ·
      <a href="{{ route('museum.crawler') }}">دربارهٔ ربات گردآوری</a><br>
      <span class="muted" style="color:#9fbfb9">داده‌های جغرافیایی پایه: ویکی‌داده (CC0) و OpenStreetMap</span>
    </div>
  </div>
</footer>
<script src="{{ asset('museum-assets/museum.js') }}?v=1" defer></script>
@stack('scripts')
</body>
</html>

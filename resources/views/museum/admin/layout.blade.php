@extends('museum.layout')
@section('title', 'مدیریت موزه — '.trim($__env->yieldContent('admin_title')))
@section('content')
<div class="admin-grid">
  <nav class="side card" aria-label="مدیریت">
    <a href="{{ route('museum.admin.dashboard') }}" @class(['on' => request()->routeIs('museum.admin.dashboard')])>داشبورد کیفیت و Big Data</a>
    <h4>بررسی و تأیید</h4>
    <a href="{{ route('museum.admin.verification') }}" @class(['on' => request()->routeIs('museum.admin.verification')])>صف تأیید ادعاها</a>
    <a href="{{ route('museum.admin.conflicts') }}" @class(['on' => request()->routeIs('museum.admin.conflicts')])>اختلاف منابع</a>
    <a href="{{ route('museum.admin.duplicates') }}" @class(['on' => request()->routeIs('museum.admin.duplicates')])>موارد تکراری</a>
    <a href="{{ route('museum.admin.candidates') }}" @class(['on' => request()->routeIs('museum.admin.candidates')])>کشف (Candidateها)</a>
    <a href="{{ route('museum.admin.submissions') }}" @class(['on' => request()->routeIs('museum.admin.submissions')])>مشارکت‌های مردمی</a>
    <h4>محتوا</h4>
    <a href="{{ route('museum.admin.entities') }}" @class(['on' => request()->routeIs('museum.admin.entities*')])>مدخل‌ها (مکان، واژه، غذا، …)</a>
    <a href="{{ route('museum.admin.media') }}" @class(['on' => request()->routeIs('museum.admin.media')])>رسانه</a>
    <h4>داده و منابع</h4>
    <a href="{{ route('museum.admin.sources') }}" @class(['on' => request()->routeIs('museum.admin.sources*')])>منابع و Crawler</a>
    <a href="{{ route('museum.admin.imports') }}" @class(['on' => request()->routeIs('museum.admin.imports*')])>گزارش Importها</a>
    <a href="{{ route('museum.admin.ai-jobs') }}" @class(['on' => request()->routeIs('museum.admin.ai-jobs')])>کارهای AI</a>
    <a href="{{ route('museum.admin.research') }}" @class(['on' => request()->routeIs('museum.admin.research')])>Research Agent</a>
    <form method="post" action="{{ route('museum.admin.logout') }}" style="margin-top:14px">@csrf<button class="btn ghost small">خروج</button></form>
  </nav>
  <div>
    @if ($errors->any())<div class="notice err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('admin')
  </div>
</div>
@endsection

@extends('museum.layout')
@section('title', 'شما هم به موزه کمک کنید')
@section('content')
<h1>شما هم به موزه کمک کنید</h1>
<p>واژه محلی، ضرب‌المثل، نام قدیمی محله، دستور غذا، خاطره یا اطلاعات تاریخی خود را ارسال کنید. هر مشارکت پیش از انتشار توسط ناظر و در صورت لزوم کارشناس بررسی می‌شود و نام شما فقط با رضایت خودتان نمایش داده می‌شود.</p>
@if ($errors->any())<div class="notice err">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
<form method="post" action="{{ route('museum.contribute.store') }}" class="card" style="max-width:720px">
  @csrf
  <label for="t">نوع مشارکت</label>
  <select id="t" name="submission_type" required>
    @foreach (['word' => 'کلمه محلی', 'sentence' => 'جمله محلی', 'proverb' => 'ضرب‌المثل یا اصطلاح', 'story' => 'داستان', 'old_name' => 'نام قدیمی محله / مکان', 'food' => 'غذا', 'recipe' => 'دستور غذا', 'historical_info' => 'اطلاعات تاریخی', 'memory' => 'خاطره', 'music' => 'موسیقی'] as $k => $label)
      <option value="{{ $k }}" @selected(old('submission_type') === $k)>{{ $label }}</option>
    @endforeach
  </select>
  <label for="title">عنوان (اختیاری)</label><input id="title" type="text" name="title" value="{{ old('title') }}" maxlength="300">
  <label for="text">متن / کلمه / نام</label><textarea id="text" name="text" required maxlength="20000">{{ old('text') }}</textarea>
  <label for="meaning">معنی یا توضیح</label><textarea id="meaning" name="meaning" maxlength="5000">{{ old('meaning') }}</textarea>
  <label for="place">منطقه (شهر، روستا یا محله)</label><input id="place" type="text" name="place_text" value="{{ old('place_text') }}">
  <label for="dialect">گویش</label><input id="dialect" type="text" name="dialect_text" value="{{ old('dialect_text') }}">
  <label for="name">نام شما (اختیاری؛ فقط با رضایت شما نمایش داده می‌شود)</label><input id="name" type="text" name="submitter_name" value="{{ old('submitter_name') }}">
  <label for="contact">راه تماس (اختیاری؛ هرگز منتشر نمی‌شود)</label><input id="contact" type="text" name="submitter_contact" value="{{ old('submitter_contact') }}">
  <div style="position:absolute;left:-5000px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
  <label class="check"><input type="checkbox" name="is_own_work" value="1"> این اطلاعات/اثر متعلق به خودم است یا از گفته‌های خانواده‌ام است.</label>
  <label class="check"><input type="checkbox" name="consent_publish" value="1" required> اجازه می‌دهم پس از بررسی، این مشارکت با ذکر منبع «مشارکت مردمی» در موزه منتشر شود.</label>
  <p style="margin-top:14px"><button class="btn">ارسال برای بررسی</button></p>
  <p class="muted">برای ارسال فایل صوتی، تصویر یا ویدیو از API <span class="ltr">POST /api/museum/submissions</span> یا تماس با موزه استفاده کنید؛ تلفظ واژه‌ها باید با صدای خود گویشور ضبط شود.</p>
</form>
@endsection

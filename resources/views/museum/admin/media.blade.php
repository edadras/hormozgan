@extends('museum.admin.layout')
@section('admin_title', 'رسانه')
@section('admin')
<h1>رسانه</h1>
<form method="post" action="{{ route('museum.admin.media.store') }}" enctype="multipart/form-data" class="card">
  @csrf
  <label>فایل</label><input type="file" name="file" required>
  <div class="grid">
    <div><label>عنوان</label><input type="text" name="title"></div>
    <div><label>پدیدآورنده / عکاس / اجراکننده</label><input type="text" name="creator"></div>
    <div><label>سال</label><input type="number" name="year"></div>
    <div><label>دقت تاریخ</label><select name="year_precision"><option value="exact">دقیق</option><option value="circa">تقریبی</option><option value="decade">دهه</option><option value="unknown">نامعلوم</option></select></div>
    <div><label>پروانه</label><select name="license">@foreach (\App\Museum\Enums\License::cases() as $l)<option value="{{ $l->value }}" @selected($l->value === 'unknown')>{{ $l->label() }}</option>@endforeach</select></div>
    <div><label>دارنده حق نشر</label><input type="text" name="copyright_holder"></div>
    <div><label>پیوند به مدخل (slug)</label><input type="text" name="entity_slug"></div>
    <div><label>نقش</label><select name="role"><option>gallery</option><option>primary</option><option>then</option><option>now</option><option>tutorial</option></select></div>
    <div><label>گوینده (برای صدا)</label><select name="speaker_id"><option value="">—</option>@foreach ($speakers as $sp)<option value="{{ $sp->id }}">{{ $sp->publicName() }} ({{ $sp->consent_status }})</option>@endforeach</select></div>
  </div>
  <label class="check"><input type="checkbox" name="is_synthetic" value="1"> صدای ماشینی/TTS است (هرگز به‌عنوان مدرک تلفظ پذیرفته نمی‌شود)</label>
  <label>توضیح</label><textarea name="description"></textarea>
  <label>بیانیه حقوق</label><input type="text" name="rights_statement">
  <p><button class="btn">بارگذاری (پیش‌نویس)</button></p>
</form>
<div class="scroll-x"><table class="data" style="margin-top:16px"><tr><th>عنوان</th><th>نوع</th><th>پروانه</th><th>وضعیت</th><th>پردازش</th><th></th></tr>
@foreach ($media as $m)<tr><td>{{ $m->title ?? $m->original_filename }}</td><td>{{ $m->media_type }}</td><td>{{ $m->licenseEnum()->label() }}</td><td>{{ $m->visibility }}</td><td>{{ $m->processing_status }}</td>
  <td><form method="post" action="{{ route('museum.admin.media.publish', $m) }}">@csrf
    @if ($m->visibility !== 'published')<button class="btn small alt" name="visibility" value="published">انتشار</button>@else<button class="btn small ghost" name="visibility" value="hidden">پنهان</button>@endif</form></td></tr>@endforeach
</table></div>
{{ $media->links('museum.partials.pager') }}
@endsection

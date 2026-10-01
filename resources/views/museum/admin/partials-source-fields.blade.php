@php $s = $s ?? null; @endphp
<div class="grid">
  <div><label>نوع</label><select name="source_type">@foreach (\App\Models\Museum\Source::TYPES as $t)<option @selected(old('source_type', $s?->source_type) === $t)>{{ $t }}</option>@endforeach</select></div>
  <div><label>پروانه</label><select name="license">@foreach (\App\Museum\Enums\License::cases() as $l)<option value="{{ $l->value }}" @selected(old('license', $s?->license ?? 'unknown') === $l->value)>{{ $l->label() }}</option>@endforeach</select></div>
  <div><label>سیاست Crawl</label><select name="crawl_policy">@foreach (['pending_review', 'allowed', 'metadata_only', 'forbidden'] as $p)<option @selected(old('crawl_policy', $s?->crawl_policy ?? 'pending_review') === $p)>{{ $p }}</option>@endforeach</select></div>
  <div><label>وضعیت بررسی منبع</label><select name="verification_status">@foreach (['unverified', 'source_verified', 'expert_verified'] as $p)<option @selected(old('verification_status', $s?->verification_status ?? 'unverified') === $p)>{{ $p }}</option>@endforeach</select></div>
  <div><label>سطح اعتبار (۱–۵)</label><input type="number" name="reliability_tier" min="1" max="5" value="{{ old('reliability_tier', $s?->reliability_tier ?? 3) }}"></div>
  <div><label>فاز</label><input type="text" name="phase" value="{{ old('phase', $s?->phase) }}" maxlength="1"></div>
  <div><label>اولویت</label><input type="number" name="priority" min="1" max="5" value="{{ old('priority', $s?->priority ?? 3) }}"></div>
</div>
<label>عنوان</label><input type="text" name="title" required value="{{ old('title', $s?->title) }}">
<div class="grid">
  <div><label>پدیدآورنده</label><input type="text" name="author" value="{{ old('author', $s?->author) }}"></div>
  <div><label>ناشر</label><input type="text" name="publisher" value="{{ old('publisher', $s?->publisher) }}"></div>
  <div><label>تاریخ انتشار</label><input type="text" name="publication_date" value="{{ old('publication_date', $s?->publication_date) }}"></div>
  <div><label>زبان</label><input type="text" name="language" value="{{ old('language', $s?->language) }}"></div>
  <div><label>کتاب / نشریه</label><input type="text" name="container_title" value="{{ old('container_title', $s?->container_title) }}"></div>
  <div><label>ISBN</label><input type="text" name="isbn" class="ltr" value="{{ old('isbn', $s?->isbn) }}"></div>
  <div><label>DOI</label><input type="text" name="doi" class="ltr" value="{{ old('doi', $s?->doi) }}"></div>
  <div><label>شناسه آرشیوی</label><input type="text" name="archive_reference" value="{{ old('archive_reference', $s?->archive_reference) }}"></div>
  <div><label>وضعیت حق نشر</label><input type="text" name="copyright_status" value="{{ old('copyright_status', $s?->copyright_status) }}"></div>
</div>
<label>URL</label><input type="url" name="url" class="ltr" value="{{ old('url', $s?->url) }}">
<label>یادداشت</label><textarea name="notes">{{ old('notes', $s?->notes) }}</textarea>

# فرمت‌های Import

همه Importها گزارش استاندارد (بند ۶۱) در `museum_import_batches` ثبت می‌کنند و در پنل مدیریت (`/museum/admin/imports`) قابل مشاهده‌اند.

## ۱. Source Registry — `museum:import:sources {path}`

فایل JSON:

```json
{ "sources": [ {
  "title": "…", "source_type": "book|article|thesis|encyclopedia|gazetteer|government_dataset|census|open_dataset|archive_document|map|newspaper|photo_archive|website|interview|field_recording|community_submission",
  "author": "…", "publisher": "…", "publication_date": "1908", "url": "https://…", "doi": "…", "isbn": "…",
  "language": "fa|en|…", "phase": "A-F", "priority": 1-5, "reliability_tier": 1-5,
  "topics": ["…"], "regions": ["…"], "compiler_notes": "ادعاهای گردآورنده؛ به‌عنوان Fact ثبت نمی‌شوند"
} ] }
```

- منابع با `license: unknown`، `crawl_policy: pending_review` و `verification_status: unverified` ثبت می‌شوند مگر صریحاً مشخص شده باشد.
- تکراری‌ها با URL متعارف (پارامترهای `utm_*` حذف می‌شوند)، DOI یا ISBN تشخیص داده می‌شوند.
- برای هر منبع وب یک Crawler **غیرفعال** ساخته می‌شود؛ فعال‌سازی نیازمند بررسی شرایط استفاده توسط انسان است.

## ۲. داده‌های جدولی مستند — `museum:import:csv {kind} {path} [--status=source_verified]`

CSV با UTF-8 و سطر عنوان. **هر سطر باید `source_uuid` یک منبع ثبت‌شده را داشته باشد**؛ سطر بدون منبع رد می‌شود. ستون‌های مشترک: `source_uuid`, `page`, `locator`, `quote` (متن عین منبع؛ به‌صورت Extract ذخیره می‌شود).

مکان‌ها با نام (هر Alias یا نام تاریخی) Resolve می‌شوند؛ اگر نام مبهم باشد (مثلاً دو روستای هم‌نام) سطر با خطا رد می‌شود تا داده به مکان اشتباه نسبت داده نشود.

| kind | ستون‌ها |
|---|---|
| `words` | `word`*, `meaning_fa`*, `meaning_en`, `transcription_fa`, `transliteration`, `ipa`, `part_of_speech`, `dialect`, `place`, `concept`, `concept_en`, `etymology`, `usage_notes` |
| `sentences` | `text_local`*, `text_fa`, `text_en`, `transcription_fa`, `ipa`, `category` (کلید دسته `sentence`)، `dialect`, `place` |
| `proverbs` | `text_local`*, `kind` (proverb/idiom/expression), `transcription_fa`, `literal_meaning`, `figurative_meaning`, `usage_context`, `backstory`, `persian_equivalent`, `dialect`, `place` |
| `historical_names` | `place`*, `historical_name`*, `local_pronunciation`, `period`, `year_from`, `year_to`, `meaning`, `reason` |
| `place_facts` | `place`*, `property`* (کلید Property Registry)، `value` (خالی = UNKNOWN)، `census_year` |

نمونه برای فرهنگ آبادی‌ها پس از OCR و بازبینی انسانی:

```csv
place,property,value,census_year,source_uuid,page,quote
<نام روستا>,population,<عدد>,1976,<uuid فرهنگ آبادی ۱۳۵۵>,<صفحه>,<سطر عین سند>
```

## ۳. ویکی‌داده

- `museum:import:wikidata [--publish]`: پیمایش زنده با `Special:EntityData` (تنها مسیر مجاز در robots.txt ویکی‌داده؛ SPARQL و `/w/api.php` استفاده نمی‌شوند). از استان تا بخش‌ها، مراکز شهرستان (P36) و جزایر (P706).
- `museum:import:wikidata-dump {latest-all.json.gz|-} [--publish]`: پوشش سطح روستا از Dump رسمی (`dumps.wikimedia.org`). دو مرحله: فیلتر موارد ایرانی دارای P131، سپس بستار عضویت از طریق P131 تا استان.

هر Fact با Locator به شکل `Q123#P1082@rev456` و Extract شامل JSON کامل Claim (همراه با ارجاعات خود ویکی‌داده) ذخیره می‌شود.

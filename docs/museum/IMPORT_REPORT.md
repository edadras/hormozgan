# گزارش Importها (STEP 12)

تاریخ اجرا: 2026-10-01 · محیط: توسعه (SQLite) · همه اعداد واقعی‌اند و از `museum_import_batches` خوانده شده‌اند.

## ۱. Source Registry — `museum:import:sources`

| Sources Found | Sources Accepted | Sources Rejected | Errors |
|---|---|---|---|
| 35 | 35 | 0 | 0 |

همه با `license=unknown`، `crawl_policy=pending_review` و `verification_status=unverified`. برای هر منبع وب یک Crawler **غیرفعال** ساخته شد. ادعاهای گردآورنده (مثل تعداد صفحات) در `metadata.compiler_notes` نگهداری می‌شوند و Fact نیستند.

## ۲. Phase A — ویکی‌داده (`museum:import:wikidata --publish`)

دسترسی فقط از مسیر `Special:EntityData` (مجاز در robots.txt). SPARQL و `/w/api.php` در robots.txt برای عامل‌های عمومی Disallow هستند و استفاده نشدند.

| اجرا | Documents | Facts (assertions) | Entities Created | Entities Matched | Duplicates | Conflicts | Errors | توضیح |
|---|---|---|---|---|---|---|---|---|
| 2 | 48 | 206 | 48 | 0 | 0 | 0 | 2 | دو پاسخ HTTP 429 (Rate limit)؛ پس از آن پشتیبانی از `Retry-After` اضافه شد |
| 3 | 66 | 303 | 18 | 48 | 0 | 0 | 0 | افزودن پیمایش P36 (مرکز) و P706 (جزیره) |
| 4 | 65 | 297 | 1 | 64 | 0 | 0 | 0 | رفع ترتیب بررسی عضویت (بندرعباس) |
| 5 | 65 | 297 | 0 | 65 | 0 | 0 | 0 | اجرای اعتبارسنجی پس از Refactor — کاملاً Idempotent |

«Facts (assertions)» شامل تأییدهای تکراری از همان منبع است؛ Factهای یکتا در پایگاه دانش: **۳۱۳**.

### وضعیت نهایی پایگاه دانش

| شاخص | مقدار |
|---|---|
| Entity (همه منتشرشده) | 67 — استان ۱، شهرستان ۱۳، بخش ۳۹، شهر ۱۲، جزیره ۲ |
| Fact | 313 (همه `source_verified`، هر کدام با منبع + Locator نسخه‌دار + Extract) |
| Fact جمعیت (با سال آمار به‌عنوان Qualifier) | 71 |
| Alias (fa/en/ar و نام‌های جایگزین) | 289 |
| یال‌های گراف (هر یال پشتیبانی‌شده با یک Fact) | 67 |
| Raw Documents (پاسخ‌های خام، با SHA-256) | 76 · 1.7 MB |
| Missing source / Conflicts / Low confidence / Duplicates | 0 / 0 / 0 / 0 |

Snapshot قابل بازتولید با Provenance کامل: [`database/data/exports/phase-a-wikidata-geography.jsonl.gz`](../../database/data/exports/phase-a-wikidata-geography.jsonl.gz) (CC0، تولیدشده با `museum:export`).

## محدودیت‌ها و گام بعد

- دهستان‌ها، روستاها، کوه‌ها و رودها از مسیر زنده قابل دسترسی نیستند (ویکی‌داده برای بخش‌ها P150 به دهستان ندارد و جستجوی معکوس از مسیرهای مجاز ممکن نیست). راه درست: `museum:import:wikidata-dump` روی Dump رسمی (پیاده‌سازی و تست‌شده، اجرا در Production به‌دلیل حجم ~۱۳۰GB).
- بعضی مقادیر جمعیت ویکی‌داده برای سال‌های غیرسرشماری (مثلاً ۲۰۰۵) برآورد هستند؛ به همین دلیل UI عبارت «آمار سال» را نشان می‌دهد نه «سرشماری». تطبیق با فرهنگ آبادی‌ها و نتایج رسمی مرکز آمار اختلاف‌ها را به‌صورت Conflict ثبت خواهد کرد.
- هیچ استخراج AI در این محیط اجرا نشد (کلید API تنظیم نشده بود)؛ Pipeline با تست‌های خودکار اعتبارسنجی شده است.
- فاز B تا F: منابع ثبت‌شده‌اند و پس از بررسی حقوقی هر منبع وارد Pipeline می‌شوند (`docs/museum/DATA_COLLECTION_PLAN.md`).

# موزه دیجیتال هرمزگان‌شناسی — Hormozgan Digital Museum

آرشیو دیجیتال، پایگاه دانش، اطلس زبانی و آرشیو تاریخی استان هرمزگان. سه اصل غیرقابل مذاکره: **Accuracy · Traceability · Preservation**.

- معماری و ERD: [`docs/museum/ARCHITECTURE.md`](docs/museum/ARCHITECTURE.md)
- برنامه گردآوری داده: [`docs/museum/DATA_COLLECTION_PLAN.md`](docs/museum/DATA_COLLECTION_PLAN.md)
- فرمت Importها: [`docs/museum/IMPORT_FORMATS.md`](docs/museum/IMPORT_FORMATS.md)
- عملیات و Production: [`docs/museum/OPERATIONS.md`](docs/museum/OPERATIONS.md)
- گزارش Importهای انجام‌شده: [`docs/museum/IMPORT_REPORT.md`](docs/museum/IMPORT_REPORT.md)

## Stack

Laravel 12 · PHP 8.3 · MySQL 8 (SQLite برای توسعه و تست) · Redis + Laravel Queue · MySQL FULLTEXT / OpenSearch · Vector store (DB یا Qdrant) · S3-compatible storage · FFmpeg · Tesseract · Leaflet + OpenStreetMap · Claude (Anthropic PHP SDK) برای استخراج و RAG.

## راه‌اندازی سریع (توسعه)

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed            # فقط داده سیستمی: نقش‌ها، انواع، Propertyها، دسته‌ها
php artisan museum:import:sources     # ثبت ۳۵ منبع اولیه (تأییدنشده، Crawl در انتظار بررسی)
php artisan museum:import:wikidata --publish   # فاز A: جغرافیای اداری از ویکی‌داده
php artisan museum:search:reindex --embed
php artisan museum:user you@example.org --role=admin
php artisan serve                     # http://127.0.0.1:8000/museum  ·  /museum/admin
php artisan queue:work --queue=museum-crawl,museum-extract,museum-ai,museum-index,museum-media,default
```

برای استخراج AI و پاسخ‌گویی RAG: `ANTHROPIC_API_KEY` و `MUSEUM_LLM_DRIVER=anthropic`. بدون آن، سیستم کار می‌کند ولی پاسخ‌گو فقط منابع مرتبط را فهرست می‌کند و هیچ متنی تولید نمی‌شود.

## تست

```bash
php artisan test
```

تست‌ها فقط با داده‌های آزمایشی برچسب‌دار («Fixture») در پایگاه داده حافظه‌ای اجرا می‌شوند.

## مسیرها

| عمومی | API | مدیریت |
|---|---|---|
| `/museum` خانه و نقشه | `/api/museum/places`, `/places/{slug}` | `/museum/admin` داشبورد کیفیت و Big Data |
| `/museum/e/{slug}` صفحه هر مدخل | `/api/museum/entities`, `/words`, `/dialects`, `/dialects/compare` | صف تأیید، اختلاف‌ها، تکراری‌ها، Candidateها |
| `/museum/facts/{uuid}` زنجیره منبع | `/api/museum/history`, `/history/names`, `/history/layers` | منابع و Crawler، Importها، کارهای AI |
| `/museum/search`, `/museum/ask` | `/api/museum/search`, `/map`, `/knowledge/*`, `POST /ask` | مدخل‌ها، Factها، رسانه، مشارکت‌ها، Research Agent |
| `/museum/language`, `/history`, `/sea/lenj`, `/kids`, `/contribute` | `POST /api/museum/submissions` | |

## داده

هیچ داده فرهنگی، تاریخی، زبانی، کشاورزی یا زندگی‌نامه‌ای ساختگی در این مخزن نیست. Seederها فقط داده سیستمی می‌سازند. داده واقعی از Importهای مستند، Pipeline پژوهشی، پنل مدیریت و مشارکت مردمی وارد می‌شود و هر Fact به منبع، صفحه/Locator و Extract خود پیوند دارد.

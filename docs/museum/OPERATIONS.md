# عملیات، Performance و Production (STEP 14)

## سرویس‌ها

| مؤلفه | Production |
|---|---|
| Web | nginx + PHP-FPM 8.3 با OPcache؛ `php artisan optimize` (config/route/view/event cache) |
| DB | MySQL 8 (`innodb_ft_min_token_size`/ngram برای FULLTEXT فارسی؛ `ngram_token_size=2`) |
| Queue | Redis؛ Workerهای جدا برای صف‌ها (`museum-crawl` با ۱ Worker برای رعایت ادب Crawl، `museum-ai` با تعداد محدود برای کنترل هزینه، `museum-extract`, `museum-index`, `museum-media`) با Supervisor یا Horizon |
| Cache/Session | Redis (`CACHE_STORE=redis`)؛ پاسخ‌های عمومی با `CacheVersion` نسخه‌دار هستند و با هر انتشار باطل می‌شوند |
| Search | `MUSEUM_SEARCH_DRIVER=mysql_fulltext` تا چند میلیون سند؛ بالاتر `opensearch` و `museum:search:reindex` |
| Vectors | `MUSEUM_VECTOR_DRIVER=qdrant` بالای ~۲۰۰هزار Chunk؛ `MUSEUM_EMBEDDINGS_DRIVER=voyage` (یا هر سرور OpenAI-compatible) برای Embedding معنایی |
| Storage | `MUSEUM_RAW_DRIVER=s3`, `MUSEUM_MEDIA_DRIVER=s3`, `MUSEUM_BACKUP_DRIVER=s3` (S3-compatible؛ Bucket جدا با Versioning و Object Lock برای Backup) |
| CDN | `MUSEUM_CDN_URL` برای رسانه‌های عمومی؛ فایل‌های محلی فقط از مسیر کنترل‌شده `/museum/files/{uuid}` سرو می‌شوند |
| OCR | `apt install tesseract-ocr tesseract-ocr-fas tesseract-ocr-ara poppler-utils` |
| ASR | سرور Whisper سازگار با OpenAI روی زیرساخت پروژه (`MUSEUM_TRANSCRIBE_URL`) تا صدای مصاحبه‌ها از کنترل پروژه خارج نشود |

## Scheduler (`routes/console.php`)

`* * * * * php artisan schedule:run` — Backup روزانه با فایل‌ها، Snapshot کیفیت ساعتی، Crawl روزانه منابع تأییدشده، مسیریابی Candidateها هر ۱۵ دقیقه، Embedding تدریجی هر ۳۰ دقیقه.

## Backup (بند ۵۱)

`museum:backup --with-files`: Dump پایگاه داده (mysqldump `--single-transaction`)، Manifest با SHA-256 همه فایل‌های خام/رسانه و شمارش جداول، و Object Store محتوامحور (هر فایل یکتا یک‌بار، هرگز بازنویسی نمی‌شود). پوشه‌های اجرای قدیمی‌تر از `MUSEUM_BACKUP_RETENTION_DAYS` حذف می‌شوند؛ Objectها باقی می‌مانند. نسخه‌های اطلاعات علاوه بر این در `museum_fact_revisions` و `museum_entity_merges` حفظ می‌شوند.

بازیابی: Import فایل `database.sql.gz`، سپس کپی `objects/xx/<sha256>` به مسیرهای ذکرشده در Manifest.

## مقیاس (بند ۵۰ و ۵۹)

- ایندکس‌های مرکب روی مسیرهای داغ: `facts(entity_id, property_id, verification_status)`, `facts(conflict_key)`, `facts(value_hash)`, `entity_aliases(normalized_alias)`, `entity_aliases(compact_key)`, `places(path)`, `search_documents` FULLTEXT.
- Import انبوه: `FactService::$deferCounters` شمارنده‌ها را در پایان Import محاسبه می‌کند.
- API: حداکثر ۱۰۰ رکورد در هر صفحه، Rate limit (۲۴۰/دقیقه؛ پرسش ۶/دقیقه؛ ارسال ۳/دقیقه).
- هیچ Crawl، OCR، استخراج AI، Embedding یا پردازش رسانه‌ای در Request وب انجام نمی‌شود؛ همه در Queue.

## امنیت و حریم خصوصی

- اطلاعات تماس مشارکت‌کنندگان و گویندگان رمزنگاری‌شده (`encrypted` cast) و هرگز در API عمومی نیست.
- صدای گوینده فقط با رضایت `publish_audio` و متن مصاحبه فقط با `publish_transcript` منتشر می‌شود.
- رسانه با پروانه `restricted` یا `unknown` منتشر نمی‌شود؛ انتشار `restricted` نیازمند مجوز `media.publish_restricted` است.
- اشخاص زنده با `privacy_level` غیر از `public_figure` فقط اطلاعات نقش عمومی دارند.

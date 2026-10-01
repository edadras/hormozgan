<?php

namespace Database\Seeders;

use App\Models\Museum\Category;
use App\Models\Museum\EntityType;
use App\Models\Museum\Permission;
use App\Models\Museum\Property;
use App\Models\Museum\RelationshipType;
use App\Models\Museum\Role;
use Illuminate\Database\Seeder;

/**
 * System data only (section 60): roles, permissions, entity types, relationship
 * types, the property registry and system categories. No cultural content.
 * Idempotent: safe to run on every deploy.
 */
class MuseumSystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->entityTypes();
        $this->relationshipTypes();
        $this->properties();
        $this->categories();
        $this->rbac();
        EntityType::flush();
    }

    /** [key, fa, en, parent, extension table, domain] */
    public const ENTITY_TYPES = [
        ['place', 'مکان', 'Place', null, 'museum_places', 'geography'],
        ['province', 'استان', 'Province', 'place', 'museum_places', 'geography'],
        ['county', 'شهرستان', 'County', 'place', 'museum_places', 'geography'],
        ['district', 'بخش', 'District', 'place', 'museum_places', 'geography'],
        ['city', 'شهر', 'City', 'place', 'museum_places', 'geography'],
        ['rural_district', 'دهستان', 'Rural district', 'place', 'museum_places', 'geography'],
        ['village', 'روستا', 'Village', 'place', 'museum_places', 'geography'],
        ['neighborhood', 'محله', 'Neighborhood', 'place', 'museum_places', 'geography'],
        ['island', 'جزیره', 'Island', 'place', 'museum_places', 'geography'],
        ['mountain', 'کوه', 'Mountain', 'place', 'museum_places', 'geography'],
        ['river', 'رودخانه', 'River', 'place', 'museum_places', 'geography'],
        ['port', 'بندر', 'Port', 'place', 'museum_places', 'geography'],
        ['bay', 'خور / خلیج', 'Bay / estuary', 'place', 'museum_places', 'geography'],
        ['historical_site', 'مکان تاریخی', 'Historical site', 'place', 'museum_places', 'history'],
        ['building', 'بنا', 'Building', 'place', 'museum_places', 'history'],
        ['market', 'بازار', 'Market', 'place', 'museum_places', 'geography'],
        ['street', 'خیابان / گذر', 'Street', 'place', 'museum_places', 'geography'],
        ['natural_feature', 'عارضه طبیعی', 'Natural feature', 'place', 'museum_places', 'nature'],
        ['region', 'منطقه', 'Region', 'place', 'museum_places', 'geography'],

        ['dialect', 'گویش', 'Dialect', null, 'museum_dialects', 'language'],
        ['word', 'واژه', 'Word', null, 'museum_words', 'language'],
        ['proverb', 'ضرب‌المثل / اصطلاح', 'Proverb / idiom', null, 'museum_proverbs', 'language'],

        ['person', 'شخصیت', 'Person', null, 'museum_people', 'people'],
        ['social_group', 'طایفه / گروه اجتماعی', 'Tribe / social group', null, null, 'people'],
        ['organization', 'سازمان', 'Organization', null, null, 'people'],
        ['occupation', 'شغل', 'Occupation', null, null, 'culture'],

        ['historical_event', 'رویداد تاریخی', 'Historical event', null, 'museum_historical_events', 'history'],
        ['document', 'سند', 'Document', null, 'museum_documents', 'archive'],
        ['interview', 'مصاحبه (تاریخ شفاهی)', 'Oral history interview', null, 'museum_interviews', 'archive'],
        ['story', 'داستان / روایت', 'Story', null, null, 'culture'],
        ['poem', 'شعر', 'Poem', null, null, 'culture'],

        ['food', 'غذا', 'Food', null, 'museum_foods', 'culture'],
        ['local_product', 'محصول محلی', 'Local product', null, null, 'culture'],
        ['tradition', 'آیین / رسم', 'Tradition', null, 'museum_traditions', 'culture'],
        ['clothing_item', 'پوشاک', 'Clothing item', null, 'museum_clothing_items', 'culture'],
        ['craft', 'صنایع دستی', 'Craft', null, 'museum_crafts', 'culture'],
        ['game', 'بازی محلی', 'Traditional game', null, 'museum_games', 'culture'],
        ['music_instrument', 'ساز', 'Musical instrument', null, 'museum_music_items', 'culture'],
        ['music_work', 'قطعه / ترانه', 'Song / music piece', null, 'museum_music_items', 'culture'],
        ['music_genre', 'گونه موسیقی', 'Music genre', null, 'museum_music_items', 'culture'],

        ['plant', 'گیاه', 'Plant', null, 'museum_plants', 'nature'],
        ['plant_variety', 'رقم گیاهی', 'Plant variety / cultivar', null, 'museum_plant_varieties', 'nature'],
        ['animal', 'جانور / آبزی', 'Animal / marine life', null, null, 'nature'],

        ['boat_type', 'نوع شناور', 'Boat type', null, null, 'maritime'],
        ['ship', 'شناور مشخص', 'Named vessel', null, null, 'maritime'],
        ['boat_part', 'جزء شناور', 'Boat part', null, null, 'maritime'],
        ['sea_route', 'مسیر دریایی', 'Sea route', null, null, 'maritime'],
        ['fishing_tool', 'ابزار صید', 'Fishing tool', null, null, 'maritime'],
        ['maritime_practice', 'دانش / فن دریانوردی', 'Maritime practice', null, null, 'maritime'],
    ];

    /** [key, fa, en, inverse_key, inverse fa, inverse en, subject types, object types, symmetric] */
    public const RELATIONSHIP_TYPES = [
        ['located_in', 'واقع در', 'located in', 'contains', 'دربرگیرنده', 'contains', null, null, false],
        ['belongs_to', 'متعلق به', 'belongs to', 'has', 'دارای', 'has', null, null, false],
        ['used_in', 'رایج در', 'used in', 'uses', 'کاربرد', 'uses', ['word', 'proverb'], null, false],
        ['spoken_in', 'گویش رایج در', 'spoken in', 'speaks', 'گویش', 'speaks', ['dialect'], null, false],
        ['cultivated_in', 'کشت در', 'cultivated in', 'cultivates', 'کشت', 'cultivates', ['plant', 'plant_variety'], null, false],
        ['native_to', 'بومی', 'native to', 'native_species', 'گونه بومی', 'native species', ['plant', 'animal'], null, false],
        ['found_in', 'یافت می‌شود در', 'found in', 'habitat_of', 'زیستگاه', 'habitat of', null, null, false],
        ['practiced_in', 'رایج در', 'practiced in', 'practices', 'آیین‌ها', 'practices', ['tradition', 'craft', 'game', 'maritime_practice'], null, false],
        ['born_in', 'زادگاه', 'born in', 'birthplace_of', 'زادگاه', 'birthplace of', ['person'], null, false],
        ['died_in', 'درگذشت در', 'died in', 'deathplace_of', 'محل درگذشت', 'death place of', ['person'], null, false],
        ['lived_in', 'ساکن', 'lived in', 'residence_of', 'محل زندگی', 'residence of', ['person', 'social_group'], null, false],
        ['member_of', 'عضو', 'member of', 'has_member', 'اعضا', 'has member', null, null, false],
        ['occurred_in', 'رخ داد در', 'occurred in', 'site_of', 'محل رویداد', 'site of', ['historical_event'], null, false],
        ['participated_in', 'مشارکت در', 'participated in', 'participant', 'شرکت‌کننده', 'participant', null, ['historical_event'], false],
        ['part_of', 'جزئی از', 'part of', 'has_part', 'اجزا', 'has part', null, null, false],
        ['variety_of', 'رقمی از', 'variety of', 'has_variety', 'ارقام', 'has variety', ['plant_variety'], ['plant'], false],
        ['ingredient_of', 'ماده اولیه', 'ingredient of', 'has_ingredient', 'مواد اولیه', 'has ingredient', null, ['food'], false],
        ['practiced_by', 'استادکار', 'practiced by', 'practices_craft', 'هنر', 'practices craft', ['craft', 'tradition', 'music_instrument'], ['person', 'social_group'], false],
        ['performed_by', 'اجرا توسط', 'performed by', 'performer_of', 'اجراها', 'performer of', ['music_work'], ['person'], false],
        ['instrument_of', 'ساز', 'instrument used in', 'uses_instrument', 'سازها', 'uses instrument', ['music_instrument'], null, false],
        ['worn_in', 'پوشش رایج در', 'worn in', 'clothing_of', 'پوشاک', 'clothing of', ['clothing_item'], null, false],
        ['mentions', 'اشاره به', 'mentions', 'mentioned_in', 'آمده در', 'mentioned in', ['document', 'interview', 'story', 'poem'], null, false],
        ['depicts', 'نمایش', 'depicts', 'depicted_in', 'تصویرشده در', 'depicted in', null, null, false],
        ['renamed_to', 'تغییر نام به', 'renamed to', 'formerly', 'نام پیشین', 'formerly', null, null, false],
        ['connects', 'وصل می‌کند', 'connects', 'connected_by', 'مسیرها', 'connected by', ['sea_route'], null, false],
        ['port_of_call', 'بندر مسیر', 'port of call', 'route_through', 'مسیرها', 'routes', ['sea_route', 'ship', 'boat_type'], ['port', 'city'], false],
        ['related_to', 'مرتبط با', 'related to', 'related_to', 'مرتبط با', 'related to', null, null, true],
    ];

    /** [key, fa, en, datatype, entity types|null, multivalued, relationship key|null, qualifiers|null, unit, group, wikidata pid] */
    public const PROPERTIES = [
        // general
        ['description', 'توضیح', 'Description', 'text', null, true, null, null, null, 'general', null],
        ['etymology', 'وجه تسمیه', 'Etymology / naming reason', 'text', null, true, null, null, null, 'general', null],
        ['history_note', 'تاریخچه', 'History', 'text', null, true, null, null, null, 'history', null],
        ['cultural_significance', 'ارزش فرهنگی', 'Cultural significance', 'text', null, true, null, null, null, 'culture', null],
        ['coordinates', 'مختصات', 'Coordinates', 'geo', null, false, null, null, null, 'geography', 'P625'],
        ['instance_of_label', 'گونه (طبق منبع)', 'Classified as (per source)', 'string', null, true, null, null, null, 'general', 'P31'],
        ['image_reference', 'تصویر در منبع', 'Image reference in source', 'string', null, true, null, null, null, 'general', 'P18'],
        // geography
        ['located_in', 'واقع در', 'Located in', 'entity', null, true, 'located_in', null, null, 'geography', 'P131'],
        ['population', 'جمعیت', 'Population', 'integer', null, false, null, ['census_year'], 'نفر', 'geography', 'P1082'],
        ['households', 'تعداد خانوار', 'Households', 'integer', null, false, null, ['census_year'], 'خانوار', 'geography', 'P1538'],
        ['area', 'مساحت', 'Area', 'decimal', null, false, null, null, 'km²', 'geography', 'P2046'],
        ['elevation', 'ارتفاع از سطح دریا', 'Elevation', 'decimal', null, false, null, null, 'm', 'geography', 'P2044'],
        ['founding_year', 'سال تأسیس', 'Founding year', 'year', null, false, null, null, null, 'history', 'P571'],
        ['admin_code', 'کد آماری', 'Statistical code', 'string', null, false, null, null, null, 'geography', null],
        ['heritage_designation', 'ثبت میراث', 'Heritage designation', 'string', null, true, null, null, null, 'history', 'P1435'],
        ['heritage_registration_number', 'شماره ثبت میراث', 'Heritage registration number', 'string', null, false, null, null, null, 'history', 'P1369'],
        ['economy', 'اقتصاد', 'Economy', 'text', null, true, null, null, null, 'economy', null],
        ['agriculture', 'کشاورزی', 'Agriculture', 'text', null, true, null, null, null, 'economy', null],
        ['occupations', 'مشاغل', 'Occupations', 'text', null, true, null, null, null, 'economy', null],
        ['water_supply', 'منابع آب', 'Water supply', 'text', null, true, null, null, null, 'geography', null],
        ['commonscat', 'رده ویکی‌انبار', 'Wikimedia Commons category', 'string', null, false, null, null, null, 'general', 'P373'],
        // language
        ['spoken_in', 'رایج در', 'Spoken in', 'entity', ['dialect'], true, 'spoken_in', null, null, 'language', null],
        ['used_in', 'کاربرد در', 'Used in', 'entity', ['word', 'proverb'], true, 'used_in', null, null, 'language', null],
        ['dialect_classification', 'طبقه‌بندی گویش', 'Dialect classification', 'text', ['dialect'], true, null, null, null, 'language', null],
        // people & history
        ['birth_year', 'سال تولد', 'Birth year', 'year', ['person'], false, null, null, null, 'people', 'P569'],
        ['death_year', 'سال درگذشت', 'Death year', 'year', ['person'], false, null, null, null, 'people', 'P570'],
        ['born_in', 'زادگاه', 'Place of birth', 'entity', ['person'], false, 'born_in', null, null, 'people', 'P19'],
        ['died_in', 'محل درگذشت', 'Place of death', 'entity', ['person'], false, 'died_in', null, null, 'people', 'P20'],
        ['profession', 'پیشه', 'Profession', 'string', ['person'], true, null, null, null, 'people', 'P106'],
        ['biography', 'زندگی‌نامه', 'Biography', 'text', ['person'], true, null, null, null, 'people', null],
        ['event_date', 'تاریخ رویداد', 'Event date', 'year', ['historical_event'], false, null, null, null, 'history', 'P585'],
        ['occurred_in', 'محل رویداد', 'Location of event', 'entity', ['historical_event'], true, 'occurred_in', null, null, 'history', 'P276'],
        ['participant', 'شرکت‌کننده', 'Participant', 'entity', ['historical_event'], true, null, null, null, 'history', 'P710'],
        ['mentions', 'اشاره به', 'Mentions', 'entity', null, true, 'mentions', null, null, 'archive', null],
        // culture
        ['region', 'منطقه', 'Region', 'entity', null, true, 'belongs_to', null, null, 'culture', null],
        ['practiced_in', 'رایج در', 'Practiced in', 'entity', null, true, 'practiced_in', null, null, 'culture', null],
        ['occasion', 'مناسبت', 'Occasion', 'text', null, true, null, null, null, 'culture', null],
        ['preparation', 'روش تهیه', 'Preparation', 'text', ['food', 'local_product'], true, null, null, null, 'culture', null],
        ['variations', 'گونه‌ها', 'Variations', 'text', null, true, null, null, null, 'culture', null],
        ['ingredient_of', 'ماده اولیه', 'Ingredient of', 'entity', null, true, 'ingredient_of', null, null, 'culture', null],
        ['material', 'جنس / مواد', 'Material', 'text', null, true, null, null, null, 'culture', null],
        ['color', 'رنگ', 'Color', 'text', null, true, null, null, null, 'culture', null],
        ['pattern', 'طرح / نقش', 'Pattern', 'text', null, true, null, null, null, 'culture', null],
        ['technique', 'روش ساخت', 'Technique', 'text', null, true, null, null, null, 'culture', null],
        ['usage', 'کاربرد', 'Usage', 'text', null, true, null, null, null, 'culture', null],
        ['tools', 'ابزار', 'Tools', 'text', null, true, null, null, null, 'culture', null],
        ['part_of', 'جزئی از', 'Part of', 'entity', null, true, 'part_of', null, null, 'culture', 'P361'],
        ['rules', 'قواعد بازی', 'Rules', 'text', ['game'], true, null, null, null, 'culture', null],
        ['equipment', 'وسایل', 'Equipment', 'text', ['game', 'craft'], true, null, null, null, 'culture', null],
        ['practitioner', 'استادکار', 'Master practitioner', 'entity', null, true, 'practiced_by', null, null, 'culture', null],
        ['performer', 'اجراکننده', 'Performer', 'entity', ['music_work'], true, 'performed_by', null, null, 'culture', null],
        ['regional_difference', 'تفاوت منطقه‌ای', 'Regional difference', 'text', null, true, null, null, null, 'culture', null],
        // nature & agriculture
        ['scientific_name', 'نام علمی', 'Scientific name', 'string', ['plant', 'animal', 'plant_variety'], false, null, null, null, 'nature', 'P225'],
        ['taxon_family', 'تیره', 'Family', 'string', ['plant', 'animal'], false, null, null, null, 'nature', null],
        ['local_name_reported', 'نام محلی (طبق منبع)', 'Local name (as reported)', 'string', null, true, null, null, null, 'language', null],
        ['cultivated_in', 'محل کشت', 'Cultivated in', 'entity', ['plant', 'plant_variety'], true, 'cultivated_in', null, null, 'nature', null],
        ['native_to', 'بومی', 'Native to', 'entity', ['plant', 'animal'], true, 'native_to', null, null, 'nature', null],
        ['variety_of', 'رقمی از', 'Variety of', 'entity', ['plant_variety'], false, 'variety_of', null, null, 'nature', null],
        ['climate', 'اقلیم مناسب', 'Climate', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['soil', 'خاک', 'Soil', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['water_requirement', 'نیاز آبی', 'Water requirement', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['planting_season', 'فصل کاشت', 'Planting season', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['propagation', 'روش تکثیر', 'Propagation', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['irrigation', 'آبیاری', 'Irrigation', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['fertilization', 'کوددهی', 'Fertilization', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['pests', 'آفات', 'Pests', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['diseases', 'بیماری‌ها', 'Diseases', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['harvest', 'برداشت', 'Harvest', 'text', ['plant', 'plant_variety'], true, null, null, null, 'nature', null],
        ['storage', 'نگهداری', 'Storage', 'text', ['plant', 'plant_variety', 'food', 'local_product'], true, null, null, null, 'nature', null],
        ['traditional_use', 'کاربرد سنتی', 'Traditional use', 'text', null, true, null, null, null, 'nature', null],
        ['commercial_use', 'کاربرد تجاری', 'Commercial use', 'text', null, true, null, null, null, 'nature', null],
        ['plant_part_used', 'بخش مورد استفاده', 'Plant part used', 'text', ['plant'], true, null, null, null, 'nature', null],
        ['fruit_appearance', 'ظاهر میوه', 'Fruit appearance', 'text', ['plant_variety', 'plant'], true, null, null, null, 'nature', null],
        ['taste', 'طعم', 'Taste', 'text', ['plant_variety', 'plant', 'food'], true, null, null, null, 'nature', null],
        ['ripening_time', 'زمان رسیدن', 'Ripening time', 'text', ['plant_variety', 'plant'], true, null, null, null, 'nature', null],
        ['market', 'بازار', 'Market', 'text', ['plant_variety', 'plant', 'local_product'], true, null, null, null, 'economy', null],
        // maritime
        ['function', 'کارکرد', 'Function', 'text', ['boat_part', 'fishing_tool', 'boat_type'], true, null, null, null, 'maritime', null],
        ['connects', 'مسیر', 'Connects', 'entity', ['sea_route'], true, 'connects', null, null, 'maritime', null],
        ['season', 'فصل', 'Season', 'text', null, true, null, null, null, 'maritime', null],
    ];

    /** scheme => [[key, fa, en]] */
    public const CATEGORIES = [
        'food' => [
            ['seafood', 'غذاهای دریایی', 'Seafood'], ['rice', 'غذاهای برنجی', 'Rice dishes'], ['bread', 'نان', 'Bread'],
            ['dessert', 'شیرینی و دسر', 'Desserts'], ['breakfast', 'صبحانه', 'Breakfast'], ['drinks', 'نوشیدنی', 'Drinks'],
            ['sauces', 'سس و چاشنی', 'Sauces'], ['pickles', 'ترشی', 'Pickles'], ['spices', 'ادویه', 'Spices'],
            ['ceremonial', 'غذاهای آیینی', 'Ceremonial foods'],
        ],
        'sentence' => [
            ['greetings', 'سلام و احوالپرسی', 'Greetings'], ['family', 'خانواده', 'Family'], ['shopping', 'خرید', 'Shopping'],
            ['market', 'بازار', 'Market'], ['sea', 'دریا', 'Sea'], ['fishing', 'ماهیگیری', 'Fishing'],
            ['agriculture', 'کشاورزی', 'Agriculture'], ['home', 'خانه', 'Home'], ['food', 'غذا', 'Food'],
            ['travel', 'سفر', 'Travel'], ['love', 'عشق', 'Love'], ['friendship', 'دوستی', 'Friendship'],
            ['children', 'کودکان', 'Children'], ['everyday', 'اصطلاحات روزمره', 'Everyday expressions'],
        ],
        'tradition' => [
            ['wedding', 'عروسی', 'Wedding'], ['birth', 'تولد', 'Birth'], ['funeral', 'سوگواری', 'Funeral'],
            ['ramadan', 'رمضان', 'Ramadan'], ['nowruz', 'نوروز', 'Nowruz'], ['sea', 'آیین‌های دریایی', 'Sea traditions'],
            ['agricultural', 'آیین‌های کشاورزی', 'Agricultural traditions'], ['women', 'آیین‌های زنان', 'Women traditions'],
            ['children_games', 'بازی‌های کودکان', 'Children games'], ['celebrations', 'جشن‌ها', 'Celebrations'],
            ['beliefs', 'باورها', 'Beliefs'], ['traditional_medicine', 'طب سنتی', 'Traditional medicine'],
        ],
        'clothing' => [
            ['women', 'زنان', 'Women'], ['men', 'مردان', 'Men'], ['children', 'کودکان', 'Children'],
            ['wedding', 'عروسی', 'Wedding'], ['daily', 'روزمره', 'Daily'], ['historical', 'تاریخی', 'Historical'],
            ['ceremonial', 'آیینی', 'Ceremonial'],
        ],
        'music' => [
            ['traditional', 'موسیقی سنتی', 'Traditional music'], ['work_songs', 'آوازهای کار', 'Work songs'],
            ['sea_songs', 'آوازهای دریایی', 'Sea songs'], ['lullabies', 'لالایی', 'Lullabies'],
            ['wedding', 'موسیقی عروسی', 'Wedding music'], ['ceremonial', 'موسیقی آیینی', 'Ceremonial music'],
        ],
        'document' => [
            ['maps', 'نقشه', 'Maps'], ['letters', 'نامه', 'Letters'], ['newspapers', 'روزنامه', 'Newspapers'],
            ['books', 'کتاب', 'Books'], ['government_documents', 'اسناد دولتی', 'Government documents'],
            ['travel_records', 'سفرنامه', 'Travel records'], ['historical_photos', 'عکس تاریخی', 'Historical photos'],
        ],
        'craft' => [
            ['embroidery', 'سوزن‌دوزی و گلابتون‌دوزی', 'Embroidery'], ['weaving', 'بافندگی و حصیربافی', 'Weaving'],
            ['boatbuilding', 'لنج‌سازی', 'Boatbuilding'], ['netmaking', 'توربافی', 'Net making'],
            ['other', 'سایر', 'Other'],
        ],
        'maritime' => [
            ['traditional_boats', 'شناورهای سنتی', 'Traditional boats'], ['lenj', 'لنج', 'Lenj'],
            ['fishing', 'صیادی', 'Fishing'], ['pearl_diving', 'غواصی مروارید', 'Pearl diving'],
            ['sailing', 'بادبانی', 'Sailing'], ['navigation', 'ناوبری', 'Navigation'], ['ports', 'بنادر', 'Ports'],
            ['sea_trade', 'تجارت دریایی', 'Sea trade'], ['fishing_tools', 'ابزار صید', 'Fishing tools'],
            ['sea_terminology', 'واژگان دریایی', 'Sea terminology'],
        ],
    ];

    public const PERMISSIONS = [
        'museum.admin' => 'Full administrative access',
        'entities.edit' => 'Create and edit entities',
        'entities.publish' => 'Publish entities',
        'entities.merge' => 'Merge duplicate entities',
        'facts.edit' => 'Create and edit facts',
        'facts.verify' => 'Mark facts as source-verified',
        'facts.community_verify' => 'Mark facts as community-verified',
        'facts.expert_verify' => 'Mark facts as expert-verified',
        'conflicts.resolve' => 'Resolve fact conflicts',
        'sources.manage' => 'Manage the source registry',
        'pipeline.run' => 'Run crawlers, imports and AI jobs',
        'candidates.review' => 'Review extraction/discovery candidates',
        'submissions.moderate' => 'Moderate community submissions',
        'submissions.expert' => 'Expert-verify community submissions',
        'media.manage' => 'Upload and manage media',
        'media.publish_restricted' => 'Publish media with restricted licenses (with documented permission)',
        'speakers.view_private' => 'View private speaker data and consent documents',
        'research.run' => 'Run the research agent',
    ];

    public const ROLES = [
        'admin' => ['مدیر', 'Administrator', ['*']],
        'editor' => ['ویراستار', 'Editor', ['entities.edit', 'entities.publish', 'facts.edit', 'facts.verify', 'sources.manage', 'media.manage', 'candidates.review', 'entities.merge']],
        'moderator' => ['ناظر', 'Moderator', ['submissions.moderate', 'facts.community_verify', 'candidates.review']],
        'expert' => ['کارشناس', 'Expert', ['facts.verify', 'facts.expert_verify', 'conflicts.resolve', 'submissions.expert', 'candidates.review', 'entities.merge']],
        'researcher' => ['پژوهشگر', 'Researcher', ['sources.manage', 'pipeline.run', 'research.run', 'candidates.review', 'facts.edit']],
        'contributor' => ['مشارکت‌کننده', 'Contributor', []],
    ];

    private function entityTypes(): void
    {
        foreach (self::ENTITY_TYPES as [$key, $fa, $en, $parent, $ext, $domain]) {
            EntityType::updateOrCreate(['key' => $key], [
                'name_fa' => $fa, 'name_en' => $en, 'extension_table' => $ext, 'domain' => $domain,
                'parent_id' => $parent ? EntityType::where('key', $parent)->value('id') : null,
                'is_system' => true,
            ]);
        }
    }

    private function relationshipTypes(): void
    {
        foreach (self::RELATIONSHIP_TYPES as [$key, $fa, $en, $inv, $invFa, $invEn, $subj, $obj, $sym]) {
            RelationshipType::updateOrCreate(['key' => $key], [
                'name_fa' => $fa, 'name_en' => $en, 'inverse_key' => $inv, 'inverse_name_fa' => $invFa,
                'inverse_name_en' => $invEn, 'subject_types' => $subj, 'object_types' => $obj, 'is_symmetric' => $sym,
            ]);
        }
    }

    private function properties(): void
    {
        $sort = 0;
        foreach (self::PROPERTIES as [$key, $fa, $en, $type, $types, $multi, $rel, $qual, $unit, $group, $pid]) {
            Property::updateOrCreate(['key' => $key], [
                'label_fa' => $fa, 'label_en' => $en, 'datatype' => $type, 'entity_types' => $types,
                'is_multivalued' => $multi, 'qualifier_keys' => $qual, 'unit' => $unit, 'group' => $group,
                'relationship_type_id' => $rel ? RelationshipType::where('key', $rel)->value('id') : null,
                'wikidata_pid' => $pid, 'sort' => $sort += 10,
            ]);
        }
    }

    private function categories(): void
    {
        foreach (self::CATEGORIES as $scheme => $items) {
            foreach ($items as $i => [$key, $fa, $en]) {
                Category::updateOrCreate(['scheme' => $scheme, 'key' => $key], ['name_fa' => $fa, 'name_en' => $en, 'sort' => ($i + 1) * 10]);
            }
        }
    }

    private function rbac(): void
    {
        foreach (self::PERMISSIONS as $key => $desc) {
            Permission::updateOrCreate(['key' => $key], ['description' => $desc]);
        }
        foreach (self::ROLES as $key => [$fa, $en, $perms]) {
            $role = Role::updateOrCreate(['key' => $key], ['name_fa' => $fa, 'name_en' => $en]);
            $ids = $perms === ['*'] ? Permission::pluck('id') : Permission::whereIn('key', $perms)->pluck('id');
            $role->permissions()->sync($ids);
        }
    }
}

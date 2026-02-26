# Anagami Finance View (AFV) – модул „Финансови справки“

> **Частно / вътрешно ползване.** Хранилището е частно и съдържа извадка от кода на Anagami Finance View (AFV). Не е самостоятелно приложение и не е предназначено за публично разпространение.

## Какво е модулът

Модулът „Финансови справки“ е част от AFV – multi-tenant SaaS платформа на Laravel (Blade, Bootstrap 4, jQuery). Той събира финансовите справки и финансовия анализ, които се изчисляват върху счетоводните записи (`fin_entries`) и сметкоплана на фирмата:

- отчет за приходите и разходите, паричен поток и справка по контрагенти;
- законови справки (опростени годишни финансови отчети по НСС) и марж по продукти и услуги;
- финансови коефициенти (системни и потребителски формули) и консолидация на правни единици.

Кодовите идентификатори са запазени от оригиналния проект: namespace-ове `...\Databackd\...`, папки `routes/databackd/` и `resources/views/databackd/`, lang файлове `databackd-*.php`, helper-и `databackd_*()`. Пътищата във файловете съвпадат с тези в Laravel проекта, така че съдържанието може да се копира директно върху него.

Не са включени (отделни модули или общи части на AFV): табло, въвеждане/импорт на данни, конструктор на справки и запазени справки (модул „Справки“), бюджети и прогнози, екипи, сигнали (alerts), банков импорт, валутни курсове и базовите справки на upstream приложението.

## Справки и функции

Показани са само неща, които съществуват в кода.

### Отчети (флаг `databackd_reports`)

| Справка | Контролер | Услуга / данни | Описание |
|---|---|---|---|
| Отчет за приходите и разходите (ОПР) | `Reports/PnlReportController` | `Services/.../Reports/PnlStatement` | ОПР по българските счетоводни стандарти, изграден от `fin_entries`. Колони по периоди, KPI (нетни приходи от продажби, оперативна печалба, нетна печалба, марж), сравнение с предишен период, данък печалба – действителен или приблизителна оценка (ставка 10%). Excel и PDF. |
| Паричен поток | `Reports/CashflowReportController` | `Services/.../Reports/CashflowStatement` | Отчет по прекия метод (подредба по НСС 7): оперативна, инвестиционна и финансова дейност. Счетоводна основа, коригирана с изменението на свързаните салда (няма касов журнал). Excel и PDF. |
| Контрагенти | `Reports/CounterpartyReportController` | `Exports/.../Reports/CounterpartyData` | Оборот, брой документи, дял и тенденция по контрагент (клиенти / доставчици), месечна разбивка за избран контрагент. Excel (два листа) и PDF. |
| Марж по продукти и услуги | `Gaps/Margins/MarginReportController` | `Services/.../Gaps/Margins/MarginReport` | Приход (от фактури, нето от кредитни известия), себестойност (количество × `products.cost_price`) и брутен марж по продукт и по продуктова група. Продукти без себестойност се отчитат отделно, не като нулева цена. Графики, Excel, PDF. |
| Годишни отчети по НСС | `Gaps/Statutory/StatutoryController` | `Services/.../Gaps/Statutory/{StatutoryReport,NssMapping}` | Опростен баланс и отчет за доходите по НСС 1 с колони за текуща и предходна година, за правна единица или консолидационна група. Явно документирано съпоставяне на сметките към редовете на отчета. Печат, PDF и Excel. |

### Анализ (флаг `databackd_analytics`)

| Функция | Контролер | Описание |
|---|---|---|
| Табло с коефициенти | `Analytics/RatioDashboardController` | Карти по групи (ликвидност, рентабилност, задлъжнялост, ефективност), стойност за избран период, сравнение с целевия диапазон, промяна спрямо предходния период и 12-месечна мини-графика. Системните показатели се създават автоматично при първо отваряне. |
| Детайл на коефициент | `Analytics/RatioDetailController` | Стойност, 18-месечна тенденция с целеви коридор, компоненти на формулата, Excel експорт. |
| Сравнение по правни единици | `Analytics/RatioComparisonController` | Матрица коефициенти × правни единици (и групата). |
| Конструктор на коефициенти | `Analytics/RatioBuilderController` | Системните показатели се включват/изключват и получават нови цели; потребителските имат пълен CRUD. Редактор на формули с преглед на живо. |
| Консолидация | `Analytics/ConsolidationController` | Консолидиран отчет за приходите и разходите и баланс за група правни единици: колони по единици, елиминации, корекции, неконтролиращо участие; детайл на елиминираните записи; Excel. |
| Групи и правила | `Analytics/ConsolidationGroupController`, `ConsolidationRuleController` | Групи, участващи правни единици (дялово участие, курс) и правила: `eliminate_counterparty`, `eliminate_account_pair`, `reclassify`, `exclude_account`; бързо добавяне на стандартните вътрешногрупови правила. |

Основни услуги на анализа:

- `FormulaEngine` – изчислява формули без `eval()` (токенизатор и shunting-yard); променливи `ACC[]`, `FLOW[]`, `TYPE[]`, `SUB[]`, `AVG()`, `REF[]`, `DAYS`.
- `SystemRatios` – 15 вградени коефициента: ликвидност 3, рентабилност 5, задлъжнялост 2, ефективност 5.
- `RatioCalculator` – изчислява стойностите по периоди и ги оценява спрямо целевия диапазон.
- `ConsolidationService` – методи `full` и `proportional`, курсове чрез `FxRateService`.

## Структура на папките

Всички пътища са относителни към корена на Laravel проекта. Общо 140 файла (без този README).

| Категория | Папка | Файлове |
|---|---|---:|
| Контролери | `app/Http/Controllers/Databackd/{Analytics,Reports,Gaps/Margins,Gaps/Statutory}` | 12 |
| Form requests | `app/Http/Requests/Databackd/Analytics` | 3 |
| Услуги | `app/Services/Databackd/{Analytics,Reports,Gaps/Margins,Gaps/Statutory}` | 9 |
| Модели | `app/Models/Databackd` (FinRatio, FinConsolidationGroup, FinConsolidationGroupEntity, FinConsolidationRule) | 4 |
| Експорти (Excel/PDF) | `app/Exports/Databackd/{Analytics,Reports,Gaps/Margins,Gaps/Statutory}` | 18 |
| Artisan команди | `app/Console/Commands/Databackd` (`databackd:check-formulas`, `databackd:check-consolidation`) | 2 |
| Маршрути | `routes/databackd` | 10 |
| Менюта | `resources/views/databackd/menu` (21, 22, 23, 25, 26, 30–34) | 10 |
| Изгледи | `resources/views/databackd/{analytics,gaps/margins,gaps/statutory,reports/pnl,reports/cashflow,reports/counterparties}` | 33 |
| Преводи (eng + bg) | `resources/lang/{eng,bg}/databackd-*.php` (по 11 на език) | 22 |
| Фрагменти от общи преводи | `resources/lang/{eng,bg}/_extract` | 8 |
| Стилове | `resources/scss/databackd` (`_analytics`, `_consolidation`, `_statements`) | 3 |
| JavaScript | `resources/js/databackd-charts.js` | 1 |
| Миграции | `database/migrations` (2 миграции + 1 фрагмент в `_extract`) | 3 |
| Seeders | `database/seeders/Databackd/Demo/43AnalyticsDemoSeeder.php` | 1 |
| Други | `.gitignore` | 1 |
| **Общо** | | **140** |

Таблици, които създава модулът: `fin_ratios`, `fin_consolidation_groups`, `fin_consolidation_group_entities`, `fin_consolidation_rules`.

## Зависимости (споделени, НЕ са включени)

Модулът разчита на следните части от AFV / базовото приложение. Те са общи за няколко модула и затова не са копирани.

**Данни и модели**
- `FinEntry`, `FinAccount`, `FinEntity`, `FinDimension` (модул „Данни“) и таблиците `fin_entries`, `fin_accounts`, `fin_entities`, `fin_dimensions`.
- `Services/Databackd/FinancialDataService`, `ChartOfAccounts`, `Data/FinEntryImporter`, `Gaps/FxRates/FxRateService`.
- Таблиците `invoices`, `invoice_items`, `credit_notes`, `credit_note_items`, `products` (margins) и `companies`.
- Колоната `products.cost_price` – вижте `database/migrations/_extract/products_cost_price.fragment.php` (фрагмент от общата миграция `2026_10_28_100000_create_databackd_gaps_tables`). Формите на продуктите ползват и ключове от `databackd-gaps-margins`.

**Споделени справочни класове (модул „Справки“)**
- `Services/Databackd/Reports/{ReportFilters,PeriodBuckets,ReportBuilder}`, `Exports/Databackd/Reports/PivotPdf`, `Exports/Databackd/Concerns/NeutralizesFormulas`.
- Партиалът `resources/views/databackd/reports/_filters.blade.php` и по желание `reports/saved/_save-button.blade.php` (ползва се чрез `@includeIf`).

**Multi-tenancy и ядро**
- `App\Traits\HasCompany`, `App\Scopes\CompanyScope`, `ActiveScope`, `App\Models\BaseModel`, `Company`, `User`, `AccountBaseController`, `CoreRequest`, `App\Helper\Reply`.
- `app/Helper/start.php` (`company()`, `user()` и др.).
- `app/Helper/databackd.php` и `app/Helper/databackd_modules.php` – helper-ите `databackd_module()`, `databackd_company_module()` и каталогът на модулните флагове (включително съответствието меню → модул).
- `config/databackd.php` (общ конфигурационен файл).
- `App\Http\Middleware\Databackd\RequireModule` – middleware за маршрутите.

**Инфраструктура**
- `RouteServiceProvider::mapDatabackdRoutes()` – зарежда всеки файл от `routes/databackd/*.php` с префикс `account` и middleware `web`, `check-company-package`, `auto-logout`, `auth`, `multi-company-select`, `email_verified`.
- `AppServiceProvider` и `resources/views/sections/menu.blade.php` – менюто се зарежда от всички файлове в `resources/views/databackd/menu`.
- Blade компоненти `x-dbk.{card,chart,empty,kpi,page-header}`, `x-sub-menu-item`, `layouts.app`; Chart.js (`vendor/jquery/Chart.min.js`), зареден в `layouts/app.blade.php` преди `databackd-charts.js`.
- Общи SCSS файлове `databackd/{tokens,components,layout,reports,...}`, от които стиловете на модула зависят.
- Общи преводи: `databackd-reports-common`, `databackd-misc`, `databackd-data-entries`, `databackd-packages`. Релевантните ключове са извадени като фрагменти в `resources/lang/{eng,bg}/_extract/` и трябва да се слеят в общите файлове.
- `DatabackdDemoSeeder` (родител на демо seeder-а за анализи).

**Пакети**: `maatwebsite/excel` (^3.1), `barryvdh/laravel-dompdf` / `dompdf/dompdf` (^3.0).

## Изисквания

- PHP ≥ 8.2
- Laravel 12 (`laravel/framework ^12.0`)
- База данни със схемата на AFV (виж зависимостите по-горе)

## Интеграция

1. Копирайте съдържанието на хранилището върху корена на Laravel проекта (пътищата съвпадат; пропуснете `README.md` и `.gitignore`, ако не ви трябват).
2. Уверете се, че споделените зависимости по-горе съществуват в проекта.
3. Слейте фрагментите от `resources/lang/{eng,bg}/_extract/` в съответните общи lang файлове; добавете колоната `products.cost_price` (виж фрагмента в `database/migrations/_extract`).
4. Стартирайте миграциите: `php artisan migrate` (създава `fin_ratios` и таблиците за консолидация).
5. В `resources/scss/main.scss` импортирайте `databackd/statements`, `databackd/analytics` и `databackd/consolidation`; в `webpack.mix.js` добавете `.scripts(['resources/js/databackd-charts.js'], 'public/js/databackd-charts.js')`; изградете активите с `npm run dev` (или `npm run prod`).
6. Изчистете кешовете (`php artisan route:clear`, `view:clear`, `config:clear`) и, ако е нужно, `Cache::forget('user_modules_<id>')`.
7. По желание: демо данни – `Database\Seeders\Databackd\Demo\AnalyticsDemoSeeder` (изисква `DatabackdDemoSeeder`); проверки – `php artisan databackd:check-formulas` и `php artisan databackd:check-consolidation`.

## Модулни флагове

Достъпът се управлява от пакетите на SaaS платформата чрез два флага. Редовете на модулите се създават от общата миграция `2026_10_28_090000_add_databackd_modules` (не е включена); имената и описанията се намират в `databackd-packages` (фрагмент в `_extract`).

| Флаг | Покрива | Маршрути (`routes/databackd`) |
|---|---|---|
| `databackd_reports` | ОПР, паричен поток, контрагенти, маржове, годишни отчети по НСС | `reports-pnl`, `reports-cashflow`, `reports-counterparties`, `gaps-margins`, `gaps-statutory` |
| `databackd_analytics` | Коефициенти, сравнение, конструктор, консолидация и правила | `analytics-ratios`, `analytics-ratio-detail`, `analytics-builder`, `analytics-consolidation`, `analytics-consolidation-rules` |

Всеки маршрут е защитен с `RequireModule::class . ':<флаг>'`, а елементите на менюто се показват само при активен флаг (`databackd_module('<флаг>')`). Годишните отчети по НСС предлагат консолидационни групи само ако фирмата има флага `databackd_analytics`.

## Забележки

- Файловете са изнесени без промяна на кода; брандът е сменен на „Anagami Finance View“ (AFV), а кодовите идентификатори са запазени умишлено.
- Не са включени `vendor`, `node_modules`, `.env`, документацията на проекта и каквито и да е тайни или данни за сървъри.
- Компилираният `public/js/databackd-charts.js` не е включен – генерира се от `resources/js/databackd-charts.js`.
- Модулът е за частно / вътрешно ползване в рамките на Anagami.

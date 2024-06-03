<?php

namespace App\Services\Databackd\Analytics;

use App\Models\Company;
use App\Models\Databackd\FinRatio;
use App\Scopes\CompanyScope;
use App\Services\Databackd\ChartOfAccounts;

/**
 * Built-in financial ratios (is_system = true) installed for every company.
 *
 * Formulas use the FormulaEngine variables. Notes on the chart of accounts (ChartOfAccounts):
 *  - SUB[interest] covers both 621 (interest expense) and 721 (interest income), so EBITDA uses ACC[621] / ACC[721] explicitly;
 *  - there is no tax expense account, so EBITDA = profit + depreciation (603) + interest expense (621) - interest income (721);
 *  - percent ratios multiply by 100, ROA / ROE / asset turnover are annualised with ANNUAL, day ratios use DAYS;
 *  - denominators that can turn negative (equity, assets) are wrapped in POS(): a loss divided by a negative equity
 *    would otherwise show up as a positive ROE and a negative debt / equity as "better than the target".
 *
 * Names, descriptions and the plain-language "how to read" texts shown in the UI come from
 * databackd-analytics-ratios.system.<code>.* (FinRatio accessors); the texts below are the stored (Bulgarian) fallback.
 */
class SystemRatios
{

    /** Net profit for the period: revenue - expenses. */
    const PROFIT = '(TYPE[revenue] - TYPE[expense])';

    /** Operating revenue: accounts 70x (sales, licences, other operating income). */
    const SALES = 'FLOW[70*]';

    /**
     * code => definition. `sort` is global (group number * 10 + position), so the list is ordered correctly
     * both by (group, sort) and by sort alone.
     */
    const DEFINITIONS = [
        'current_ratio' => [
            'name' => 'Текуща ликвидност',
            'group' => 'liquidity',
            'formula' => 'SUB[current_asset] / SUB[current_liability]',
            'format' => 'ratio',
            'target_min' => 1.2,
            'target_max' => 2.5,
            'higher_is_better' => true,
            'description' => 'Текущи активи / текущи задължения. Показва дали фирмата може да покрие краткосрочните си задължения с краткотрайните си активи. Под 1 означава недостиг на оборотен капитал, а твърде високата стойност - неефективно държани средства.',
            'sort' => 11,
        ],
        'quick_ratio' => [
            'name' => 'Бърза ликвидност',
            'group' => 'liquidity',
            'formula' => '(SUB[cash] + SUB[receivable]) / SUB[current_liability]',
            'format' => 'ratio',
            'target_min' => 0.8,
            'target_max' => 1.5,
            'higher_is_better' => true,
            'description' => '(Парични средства + вземания) / текущи задължения. По-строг показател от текущата ликвидност, защото не отчита материалните запаси, които се реализират по-бавно.',
            'sort' => 12,
        ],
        'cash_ratio' => [
            'name' => 'Абсолютна ликвидност',
            'group' => 'liquidity',
            'formula' => 'SUB[cash] / SUB[current_liability]',
            'format' => 'ratio',
            'target_min' => 0.2,
            'target_max' => 0.8,
            'higher_is_better' => true,
            'description' => 'Парични средства / текущи задължения. Показва каква част от краткосрочните задължения може да бъде погасена веднага, само с наличните пари в брой и по банкови сметки.',
            'sort' => 13,
        ],

        'net_margin' => [
            'name' => 'Нетен марж на печалбата',
            'group' => 'profitability',
            'formula' => '(TYPE[revenue] - TYPE[expense]) / TYPE[revenue] * 100',
            'format' => 'percent',
            'target_min' => 5,
            'target_max' => 20,
            'higher_is_better' => true,
            'description' => 'Нетна печалба / приходи, в проценти. Колко евро печалба остава от всяко евро приходи след покриване на всички разходи.',
            'sort' => 21,
        ],
        'gross_margin' => [
            'name' => 'Брутен марж',
            'group' => 'profitability',
            'formula' => '(FLOW[70*] - SUB[cogs]) / FLOW[70*] * 100',
            'format' => 'percent',
            'target_min' => 30,
            'target_max' => 60,
            'higher_is_better' => true,
            'description' => '(Приходи от дейността - себестойност) / приходи от дейността, в проценти. Себестойността е разходите за материали (подтип „Себестойност“). Показва колко остава за покриване на оперативните разходи.',
            'sort' => 22,
        ],
        'ebitda_margin' => [
            'name' => 'EBITDA марж',
            'group' => 'profitability',
            'formula' => '(TYPE[revenue] - TYPE[expense] + SUB[depreciation] + ACC[621] - ACC[721]) / FLOW[70*] * 100',
            'format' => 'percent',
            'target_min' => 10,
            'target_max' => 30,
            'higher_is_better' => true,
            'description' => 'EBITDA / приходи от дейността, в проценти. EBITDA = печалба + амортизации + разходи за лихви - приходи от лихви (в плана със сметки няма разход за данъци). Показва оперативната рентабилност преди финансиране и амортизации.',
            'sort' => 23,
        ],
        'roa' => [
            'name' => 'Рентабилност на активите (ROA)',
            'group' => 'profitability',
            'formula' => '(TYPE[revenue] - TYPE[expense]) / POS(AVG(TYPE[asset])) * 100 * ANNUAL',
            'format' => 'percent',
            'target_min' => 5,
            'target_max' => 15,
            'higher_is_better' => true,
            'description' => 'Нетна печалба / средна стойност на активите, в проценти на годишна база. Показва колко ефективно фирмата използва активите си, за да генерира печалба.',
            'sort' => 24,
        ],
        'roe' => [
            'name' => 'Рентабилност на капитала (ROE)',
            'group' => 'profitability',
            'formula' => '(TYPE[revenue] - TYPE[expense]) / POS(AVG(TYPE[equity])) * 100 * ANNUAL',
            'format' => 'percent',
            'target_min' => 10,
            'target_max' => 25,
            'higher_is_better' => true,
            'description' => 'Нетна печалба / среден собствен капитал, в проценти на годишна база. Показва каква възвръщаемост получават собствениците на вложения от тях капитал. При отрицателен собствен капитал не се изчислява.',
            'sort' => 25,
        ],

        'debt_ratio' => [
            'name' => 'Коефициент на задлъжнялост',
            'group' => 'leverage',
            'formula' => 'TYPE[liability] / TYPE[asset]',
            'format' => 'ratio',
            'target_min' => 0.3,
            'target_max' => 0.7,
            'higher_is_better' => false,
            'description' => 'Общо задължения / общо активи. Дял от активите, финансиран с привлечени средства. Над 0,7 финансовият риск е висок, а твърде ниска стойност може да означава неизползван финансов ливъридж.',
            'sort' => 31,
        ],
        'debt_to_equity' => [
            'name' => 'Дълг / капитал',
            'group' => 'leverage',
            'formula' => 'TYPE[liability] / POS(TYPE[equity])',
            'format' => 'ratio',
            'target_min' => 0.5,
            'target_max' => 1.5,
            'higher_is_better' => false,
            'description' => 'Общо задължения / собствен капитал. Показва колко евро привлечени средства има на всяко евро собствен капитал. По-ниската стойност означава по-голяма финансова независимост. При отрицателен собствен капитал не се изчислява.',
            'sort' => 32,
        ],

        'receivable_days' => [
            'name' => 'Обращаемост на вземанията (дни)',
            'group' => 'efficiency',
            'formula' => 'AVG(SUB[receivable]) / FLOW[70*] * DAYS',
            'format' => 'days',
            'target_min' => null,
            'target_max' => 45,
            'higher_is_better' => false,
            'description' => 'Средни вземания от клиенти / приходи от дейността x брой дни в периода. Средният брой дни, за които клиентите плащат. По-малко е по-добре; над 45 дни рискът от просрочие расте.',
            'sort' => 41,
        ],
        'payable_days' => [
            'name' => 'Обращаемост на задълженията (дни)',
            'group' => 'efficiency',
            'formula' => 'AVG(ACC[401]) / (SUB[cogs] + SUB[opex]) * DAYS',
            'format' => 'days',
            'target_min' => 20,
            'target_max' => 60,
            'higher_is_better' => false,
            'description' => 'Средни задължения към доставчици / (себестойност + оперативни разходи) x брой дни в периода. Средният брой дни, за които фирмата плаща на доставчиците си. Над 60 дни означава риск от забавени плащания.',
            'sort' => 42,
        ],
        'inventory_days' => [
            'name' => 'Обращаемост на материалните запаси (дни)',
            'group' => 'efficiency',
            'formula' => 'AVG(SUB[inventory]) / SUB[cogs] * DAYS',
            'format' => 'days',
            'target_min' => 20,
            'target_max' => 60,
            'higher_is_better' => false,
            'description' => 'Средни материални запаси / себестойност x брой дни в периода. Колко дни средно стоят запасите, преди да бъдат използвани или продадени. По-малко е по-добре, защото замразеният в запаси капитал е по-малък.',
            'sort' => 43,
        ],
        'asset_turnover' => [
            'name' => 'Оборот на активите',
            'group' => 'efficiency',
            'formula' => 'FLOW[70*] / POS(AVG(TYPE[asset])) * ANNUAL',
            'format' => 'ratio',
            'target_min' => 0.8,
            'target_max' => 2,
            'higher_is_better' => true,
            'description' => 'Приходи от дейността / средна стойност на активите, на годишна база. Колко евро приходи генерира всяко евро активи. По-високата стойност означава по-ефективно използване на активите.',
            'sort' => 44,
        ],
        'payroll_to_revenue' => [
            'name' => 'Разходи за персонал / приходи',
            'group' => 'efficiency',
            'formula' => 'SUB[payroll] / FLOW[70*] * 100',
            'format' => 'percent',
            'target_min' => 20,
            'target_max' => 40,
            'higher_is_better' => false,
            'description' => 'Разходи за заплати и осигуровки / приходи от дейността, в проценти. Колко от приходите се изразходват за персонал. Над 40 % натоварва печалбата, особено в бизнес с ниски маржове.',
            'sort' => 45,
        ],
    ];

    /** @return array<string, array> code => definition */
    public static function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /**
     * Create the missing system ratios of a company. Idempotent: existing ratios (matched by code) are left
     * untouched, so targets and the active flag edited by the client survive.
     *
     * @return int number of ratios created
     */
    public static function installFor(Company $c): int
    {
        return self::installForCompanyId((int)$c->id);
    }

    /**
     * Brings the locked part of the existing system ratios (formula, name, group, format, description) up to date with
     * DEFINITIONS, e.g. after a formula fix. Targets, direction and the active flag stay as the client set them.
     *
     * @return int number of ratios updated
     */
    public static function syncForCompanyId(int $companyId): int
    {
        $updated = 0;
        $ratios = FinRatio::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('is_system', true)
            ->whereIn('code', array_keys(self::DEFINITIONS))
            ->get();

        foreach ($ratios as $ratio) {
            $d = self::DEFINITIONS[$ratio->code];
            $ratio->setRawAttributes(array_merge($ratio->getAttributes(), [
                'formula' => $d['formula'], 'name' => $d['name'], 'group' => $d['group'], 'format' => $d['format'], 'description' => $d['description'],
            ]));

            if ($ratio->isDirty()) {
                $ratio->save();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Same as installFor() for callers that only have the company id (controllers).
     *
     * @return int number of ratios created
     */
    public static function installForCompanyId(int $companyId): int
    {
        $existing = FinRatio::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->pluck('code')
            ->all();

        $created = 0;

        foreach (self::DEFINITIONS as $code => $d) {
            if (in_array($code, $existing, true)) {
                continue;
            }

            FinRatio::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $companyId,
                'code' => $code,
                'name' => $d['name'],
                'group' => $d['group'],
                'formula' => $d['formula'],
                'format' => $d['format'],
                'target_min' => $d['target_min'],
                'target_max' => $d['target_max'],
                'higher_is_better' => $d['higher_is_better'],
                'description' => $d['description'],
                'is_system' => true,
                'is_active' => true,
                'sort' => $d['sort'],
            ]);
            $created++;
        }

        return $created;
    }

}

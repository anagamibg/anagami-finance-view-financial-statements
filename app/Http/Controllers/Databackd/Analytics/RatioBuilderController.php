<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Analytics\StoreRatioRequest;
use App\Http\Requests\Databackd\Analytics\UpdateRatioRequest;
use App\Http\Requests\Databackd\Analytics\UpdateSystemRatioRequest;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Analytics\FormulaEngine;
use App\Services\Databackd\Analytics\RatioCalculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ratio builder: list of ratios (system ones can be switched on/off and get new targets,
 * custom ones have full CRUD) and the formula editor with a live preview.
 */
class RatioBuilderController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-builder.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function index()
    {
        $ratios = FinRatio::orderBy('sort')->orderBy('name')->get();
        $this->dependents = $this->dependents($ratios);

        $byGroup = $ratios->groupBy('group');
        $groups = [];

        foreach (FinRatio::GROUPS as $group) {
            if ($byGroup->has($group)) {
                $groups[$group] = $byGroup->get($group);
            }
        }

        $this->groups = $groups;

        $this->totalRatios = $ratios->count();
        $this->systemRatios = $ratios->where('is_system', true)->count();
        $this->customRatios = $ratios->where('is_system', false)->count();
        $this->activeRatios = $ratios->where('is_active', true)->count();

        return view('databackd.analytics.builder.index', $this->data);
    }

    public function create()
    {
        $this->ratio = new FinRatio([
            'group' => 'custom', 'format' => 'ratio', 'higher_is_better' => true, 'is_active' => true,
        ]);
        $this->pageTitle = __('databackd-analytics-builder.form.title_create');

        return $this->formView();
    }

    public function edit($id)
    {
        $this->ratio = FinRatio::findOrFail($id);

        if ($this->ratio->is_system) {
            return redirect()->route('databackd.analytics.builder.index');
        }

        $this->pageTitle = __('databackd-analytics-builder.form.title_edit');

        return $this->formView();
    }

    /** Quick edit of the targets of a system ratio (right modal). */
    public function targets($id)
    {
        $this->ratio = FinRatio::findOrFail($id);
        $this->pageTitle = __('databackd-analytics-builder.targets.title');
        $this->view = 'databackd.analytics.builder.targets';

        if (request()->ajax()) {
            return $this->returnAjax($this->view);
        }

        return view('databackd.analytics.builder.show', $this->data);
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function store(StoreRatioRequest $request)
    {
        $ratio = new FinRatio();
        $ratio->company_id = company()->id;
        $ratio->code = $this->generateCode((string)$request->name);
        $ratio->is_system = false;
        $ratio->sort = (int)FinRatio::max('sort') + 10;
        $this->fill($ratio, $request);
        $ratio->save();

        return Reply::success(__('databackd-analytics-builder.messages.created'));
    }

    public function update(UpdateRatioRequest $request, $id)
    {
        $ratio = FinRatio::findOrFail($id);

        if ($ratio->is_system) {
            return Reply::error(__('databackd-analytics-builder.messages.system_locked'));
        }

        // The code never changes: other ratios may reference it with REF[code]
        $this->fill($ratio, $request);
        $ratio->save();

        return Reply::success(__('databackd-analytics-builder.messages.updated'));
    }

    public function updateTargets(UpdateSystemRatioRequest $request, $id)
    {
        $ratio = FinRatio::findOrFail($id);
        $ratio->target_min = $request->filled('target_min') ? (float)$request->target_min : null;
        $ratio->target_max = $request->filled('target_max') ? (float)$request->target_max : null;
        $ratio->higher_is_better = $request->boolean('higher_is_better');
        $ratio->save();

        return Reply::success(__('databackd-analytics-builder.messages.targets_updated'));
    }

    /** Activate / deactivate a ratio (system or custom). */
    public function toggle($id)
    {
        $ratio = FinRatio::findOrFail($id);
        $ratio->is_active = !$ratio->is_active;
        $ratio->save();

        return Reply::success(__($ratio->is_active
            ? 'databackd-analytics-builder.messages.activated'
            : 'databackd-analytics-builder.messages.deactivated'));
    }

    /** Only custom ratios that no other ratio points to with REF[code] can be deleted. */
    public function destroy($id)
    {
        $ratio = FinRatio::findOrFail($id);

        if ($ratio->is_system) {
            return Reply::error(__('databackd-analytics-builder.messages.system_locked'));
        }

        $users = $this->dependents(FinRatio::all())[$ratio->code] ?? [];

        if ($users) {
            // the message is shown as HTML by toastr (froiden-helper) and the names are user input
            return Reply::error(__('databackd-analytics-builder.messages.in_use', ['names' => implode(', ', array_map('e', $users))]));
        }

        $ratio->delete();

        return Reply::success(__('databackd-analytics-builder.messages.deleted'));
    }

    /**
     * Live preview of a formula (AJAX).
     *
     * Request: formula, period_type (month|quarter|year), period, entity_id (empty = all entities), format,
     * target_min, target_max, higher_is_better, code (code of the edited ratio), name (to suggest a code).
     *
     * Response: {valid, errors[], value, formatted, status, status_label, period_label, code, variables[]}
     */
    public function preview(Request $request)
    {
        $formula = trim(mb_substr((string)$request->input('formula', ''), 0, 2000));
        $engine = new FormulaEngine(company()->id);
        $code = $this->previewCode($request);

        $response = [
            'valid' => false, 'empty' => $formula === '', 'errors' => [], 'value' => null, 'formatted' => '—',
            'status' => 'none', 'status_label' => __('databackd-analytics-common.statuses.none'),
            'period_label' => null, 'code' => $code, 'variables' => [],
        ];

        if ($formula === '') {
            return response()->json($response);
        }

        $selfCode = $request->filled('code') ? (string)$request->input('code') : null;
        $errors = $engine->validate($formula, $selfCode);

        if ($errors) {
            $response['errors'] = $errors;

            return response()->json($response);
        }

        [$from, $to, $label] = $this->resolvePeriod((string)$request->input('period_type', 'month'), (string)$request->input('period', ''));
        $filters = $request->filled('entity_id') ? ['entity_ids' => [(int)$request->input('entity_id')]] : [];
        $format = in_array($request->input('format'), FinRatio::FORMATS, true) ? $request->input('format') : 'ratio';

        $explain = $engine->explain($formula, $from, $to, $filters);

        if ($explain['error']) {
            $response['errors'] = [$explain['error']];

            return response()->json($response);
        }

        $ratio = new FinRatio([
            'formula' => $formula,
            'format' => $format,
            'target_min' => $request->filled('target_min') && is_numeric($request->input('target_min')) ? (float)$request->input('target_min') : null,
            'target_max' => $request->filled('target_max') && is_numeric($request->input('target_max')) ? (float)$request->input('target_max') : null,
            'higher_is_better' => filter_var($request->input('higher_is_better'), FILTER_VALIDATE_BOOLEAN),
        ]);
        $value = $explain['value'];
        $calc = new RatioCalculator($engine);
        $status = $calc->status($ratio, $value);

        $response['valid'] = true;
        $response['value'] = $value;
        $response['formatted'] = RatioCalculator::format($ratio, $value);
        $response['status'] = $status;
        $response['status_label'] = $calc->statusLabel($ratio, $value);
        $response['period_label'] = $label;
        $response['variables'] = array_map(fn($v) => [
            'key' => $v['key'],
            'label' => $v['label'],
            'kind' => $v['kind'],
            'value' => $v['value'],
            'formatted' => $v['value'] === null ? '—' : RatioCalculator::number($v['value'], 2),
        ], $explain['variables']);

        return response()->json($response);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function formView()
    {
        $this->accounts = FinAccount::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'subtype']);
        $this->otherRatios = FinRatio::when($this->ratio->exists, fn($q) => $q->where('id', '!=', $this->ratio->id))
            ->orderBy('name')->get(['id', 'code', 'name']);
        $this->entities = FinEntity::where('is_active', true)->orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->types = FormulaEngine::TYPES;
        $this->subtypes = FormulaEngine::SUBTYPES;
        $this->periods = $this->periodOptions();

        return view('databackd.analytics.builder.form', $this->data);
    }

    private function fill(FinRatio $ratio, StoreRatioRequest $request): void
    {
        $ratio->name = trim($request->name);
        $ratio->group = $request->group;
        $ratio->format = $request->format;
        $ratio->formula = trim(preg_replace('/\s+/', ' ', (string)$request->formula));
        $ratio->target_min = $request->filled('target_min') ? (float)$request->target_min : null;
        $ratio->target_max = $request->filled('target_max') ? (float)$request->target_max : null;
        $ratio->higher_is_better = $request->boolean('higher_is_better');
        $ratio->description = $request->filled('description') ? trim($request->description) : null;
        $ratio->is_active = $request->boolean('is_active');
    }

    /** Code shown in the preview: the real one when editing, a suggestion when creating. */
    private function previewCode(Request $request): ?string
    {
        if ($request->filled('code')) {
            return (string)$request->input('code');
        }

        return trim((string)$request->input('name', '')) !== '' ? $this->generateCode((string)$request->input('name')) : null;
    }

    /**
     * Unique code for the company: "custom_" + transliterated name (a-z, 0-9, _), at most 60 characters.
     * Codes are lower case because REF[...] arguments are lower-cased by the formula parser.
     */
    public function generateCode(string $name): string
    {
        $slug = Str::slug(self::transliterate($name), '_');
        $base = rtrim(substr('custom_' . ($slug !== '' ? $slug : 'ratio'), 0, 52), '_');
        $taken = FinRatio::where('company_id', company()->id)->where('code', 'like', $base . '%')->pluck('code')->all();
        $code = $base;
        $i = 2;

        while (in_array($code, $taken, true)) {
            $code = $base . '_' . $i++;
        }

        return $code;
    }

    /** Bulgarian Cyrillic to Latin (streamlined system), other characters are left to Str::slug. */
    private static function transliterate(string $text): string
    {
        $map = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sht',
            'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya',
        ];

        return strtr(mb_strtolower($text), $map);
    }

    /**
     * Ratio code => names of the ratios whose formulas point to it with REF[code].
     *
     * @return array<string, string[]>
     */
    private function dependents($ratios): array
    {
        $engine = new FormulaEngine(company()->id);
        $out = [];

        foreach ($ratios as $ratio) {
            foreach ($engine->variables((string)$ratio->formula) as $v) {
                if ($v['kind'] === 'REF') {
                    $out[$v['arg']][$ratio->id] = $ratio->name;
                }
            }
        }

        return array_map(fn($names) => array_values($names), $out);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string} from, to, label
     */
    private function resolvePeriod(string $type, string $value): array
    {
        $options = $this->periodOptions();
        $list = $options[in_array($type, ['month', 'quarter', 'year'], true) ? $type : 'month'];

        foreach ($list as $o) {
            if ($o['value'] === $value) {
                return [Carbon::parse($o['from']), Carbon::parse($o['to']), $o['label']];
            }
        }

        $default = $options['month'][0];

        return [Carbon::parse($default['from']), Carbon::parse($default['to']), $default['label']];
    }

    /**
     * Months, quarters and years covered by the financial data (newest first).
     *
     * @return array{month: array, quarter: array, year: array, default: string}
     */
    private function periodOptions(): array
    {
        $min = FinEntry::min('entry_date');
        $max = FinEntry::max('entry_date');
        $end = $max ? Carbon::parse($max)->startOfMonth() : Carbon::today()->startOfMonth();
        $start = $min ? Carbon::parse($min)->startOfMonth() : $end->copy()->subMonths(11);
        $locale = app()->getLocale() === 'bg' ? 'bg' : 'en';

        $months = [];

        for ($d = $end->copy(); $d->gte($start); $d->subMonthNoOverflow()) {
            $months[] = [
                'value' => $d->format('Y-m'),
                'label' => Str::ucfirst($d->copy()->locale($locale)->isoFormat('MMMM YYYY')),
                'from' => $d->toDateString(),
                'to' => $d->copy()->endOfMonth()->toDateString(),
            ];
        }

        $quarters = [];

        for ($d = $end->copy()->startOfQuarter(); $d->gte($start->copy()->startOfQuarter()); $d->subQuarterNoOverflow()) {
            $quarters[] = [
                'value' => $d->format('Y') . '-Q' . $d->quarter,
                'label' => __('databackd-analytics-builder.quarter_label', ['q' => $d->quarter, 'y' => $d->year]),
                'from' => $d->toDateString(),
                'to' => $d->copy()->endOfQuarter()->toDateString(),
            ];
        }

        $years = [];

        for ($y = $end->year; $y >= $start->year; $y--) {
            $years[] = [
                'value' => (string)$y,
                'label' => (string)$y,
                'from' => Carbon::create($y, 1, 1)->toDateString(),
                'to' => Carbon::create($y, 12, 31)->toDateString(),
            ];
        }

        return ['month' => $months, 'quarter' => $quarters, 'year' => $years, 'default' => $months[0]['value']];
    }

}

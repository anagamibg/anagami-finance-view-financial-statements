<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinConsolidationGroupEntity;
use App\Models\Databackd\FinConsolidationRule;
use App\Models\Databackd\FinEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Consolidation groups: CRUD, member legal entities and the group setup page (entities + rules).
 */
class ConsolidationGroupController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-consolidation-rules.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $groups = FinConsolidationGroup::withCount(['groupEntities', 'rules'])->orderBy('name')->get();

        $includedCounts = FinConsolidationGroupEntity::where('include', true)
            ->selectRaw('group_id, count(*) as cnt')->groupBy('group_id')->pluck('cnt', 'group_id');

        $groups->each(fn($g) => $g->included_count = (int)($includedCounts[$g->id] ?? 0));

        $this->groups = $groups;
        $this->entitiesTotal = FinEntity::count();
        $this->membersTotal = (int)$groups->sum('group_entities_count');
        $this->rulesTotal = (int)$groups->sum('rules_count');
        $this->consolidationRoute = \Route::has('databackd.analytics.consolidation.index')
            ? 'databackd.analytics.consolidation.index' : null;

        return view('databackd.analytics.consolidation-rules.index', $this->data);
    }

    public function create()
    {
        $this->group = new FinConsolidationGroup([
            'base_currency' => 'EUR', 'method' => 'full',
            'eliminate_intercompany' => true, 'minority_interest' => true,
        ]);

        return view('databackd.analytics.consolidation-rules.group-form', $this->data);
    }

    public function store(Request $request)
    {
        $validator = $this->validator($request);

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $group = new FinConsolidationGroup();
        $group->company_id = company()->id;
        $this->fill($group, $request);
        $group->save();

        return Reply::successWithData(__('databackd-analytics-consolidation-rules.messages.group_created'), [
            'group_url' => route('databackd.analytics.consolidation-rules.show', $group->id),
        ]);
    }

    public function show($id)
    {
        $group = FinConsolidationGroup::findOrFail($id);
        $this->pageTitle = __('databackd-analytics-consolidation-rules.settings_title');

        $entities = FinEntity::orderBy('sort')->orderBy('name')->get();
        $memberships = FinConsolidationGroupEntity::where('group_id', $group->id)->get()->keyBy('entity_id');
        $rules = $group->rules()->get();
        $accountNames = FinAccount::pluck('name', 'code')->mapWithKeys(fn($n, $c) => [(string)$c => $n]);

        $this->group = $group;
        $this->entities = $entities;
        $this->memberships = $memberships;
        $this->includedCount = $memberships->where('include', true)->count();
        $this->rules = $rules;
        $this->ruleSummaries = $rules->mapWithKeys(
            fn($r) => [$r->id => ConsolidationRuleController::summaryHtml($r, $accountNames)]
        );
        $this->ruleTypes = FinConsolidationRule::TYPES;
        $this->consolidationUrl = \Route::has('databackd.analytics.consolidation.index')
            ? route('databackd.analytics.consolidation.index', ['group' => $group->id]) : null;
        // audit 2026-10-30 (G11): legal entities belong to databackd_data
        $this->entitiesUrl = \Route::has('databackd.data.entities.index') && databackd_module('databackd_data') ? route('databackd.data.entities.index') : null;

        return view('databackd.analytics.consolidation-rules.show', $this->data);
    }

    public function edit($id)
    {
        $this->group = FinConsolidationGroup::findOrFail($id);

        return view('databackd.analytics.consolidation-rules.group-form', $this->data);
    }

    public function update(Request $request, $id)
    {
        $group = FinConsolidationGroup::findOrFail($id);
        $validator = $this->validator($request);

        if ($validator->fails()) {
            return Reply::formErrors($validator);
        }

        $oldCurrency = strtoupper(trim((string)$group->getOriginal('base_currency')));
        $this->fill($group, $request);
        $cleared = 0;

        DB::transaction(function () use ($group, $oldCurrency, &$cleared) {
            // AFV (S12 review A): a manual member rate means "amount_base x rate = amount in the GROUP currency", so it is
            // tied to the old group currency - after a change every manual rate of the group is wrong (before only the
            // members whose currency equals the new group currency were cleared; the others kept a rate to the old one).
            if ($oldCurrency !== strtoupper(trim((string)$group->base_currency))) {
                $cleared = FinConsolidationGroupEntity::where('group_id', $group->id)->whereNotNull('fx_rate')->update(['fx_rate' => null]);
            }

            $group->save();
        });

        if ($cleared > 0) {
            return Reply::successWithData(__('databackd-analytics-consolidation-rules.messages.group_updated_fx_cleared', [
                'count' => $cleared,
                'old' => $oldCurrency,
                'new' => strtoupper((string)$group->base_currency),
            ]), ['fx_cleared' => $cleared]);
        }

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.group_updated'));
    }

    public function destroy($id)
    {
        $group = FinConsolidationGroup::findOrFail($id);

        DB::transaction(function () use ($group) {
            FinConsolidationRule::where('group_id', $group->id)->delete();
            FinConsolidationGroupEntity::where('group_id', $group->id)->delete();
            $group->delete();
        });

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.group_deleted'));
    }

    /**
     * Saves the membership of all legal entities at once.
     * Input: entities[<entity_id>][member|include|ownership_pct|fx_rate].
     */
    public function updateEntities(Request $request, $id)
    {
        $group = FinConsolidationGroup::findOrFail($id);
        $entities = FinEntity::get()->keyBy('id');
        $input = (array)$request->input('entities', []);
        $rows = [];
        $errors = [];

        foreach ($input as $entityId => $row) {
            if (!$entities->has((int)$entityId)) {
                return Reply::error(__('databackd-analytics-consolidation-rules.validation.entity_unknown'));
            }

            if (empty($row['member'])) {
                continue;
            }

            $entity = $entities->get((int)$entityId);
            $pct = $this->decimal($row['ownership_pct'] ?? null);
            $fx = $this->decimal($row['fx_rate'] ?? null);

            // AFV (S12): the error is shown next to the field (keyed by the input id), not only as a toast
            $rawPct = trim((string)($row['ownership_pct'] ?? ''));
            $rawFx = trim((string)($row['fx_rate'] ?? ''));

            if (($rawPct !== '' && $pct === null) || ($pct !== null && ($pct < 0 || $pct > 100))) {
                $errors['ent_pct_' . $entity->id] = __('databackd-analytics-consolidation-rules.validation.ownership_range');
            }

            if (($rawFx !== '' && $fx === null) || ($fx !== null && ($fx <= 0 || $fx > 999999999))) {
                $errors['ent_fx_' . $entity->id] = __('databackd-analytics-consolidation-rules.validation.fx_positive');
            }

            $sameCurrency = strtoupper((string)$entity->currency) === strtoupper((string)$group->base_currency);

            $rows[(int)$entityId] = [
                'include' => !empty($row['include']),
                'ownership_pct' => $pct,
                // The rate only makes sense for entities in a different currency
                'fx_rate' => $sameCurrency ? null : $fx,
            ];
        }

        if ($errors) {
            return Reply::formErrors(Validator::make([], [])->after(function ($v) use ($errors) {
                foreach ($errors as $field => $message) {
                    $v->errors()->add($field, $message);
                }
            }));
        }

        DB::transaction(function () use ($group, $rows) {
            FinConsolidationGroupEntity::where('group_id', $group->id)
                ->whereNotIn('entity_id', array_keys($rows) ?: [0])->delete();

            foreach ($rows as $entityId => $values) {
                $member = FinConsolidationGroupEntity::firstOrNew(['group_id' => $group->id, 'entity_id' => $entityId]);
                $member->company_id = company()->id;
                $member->include = $values['include'];
                $member->ownership_pct = $values['ownership_pct'];
                $member->fx_rate = $values['fx_rate'];
                $member->save();
            }
        });

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.entities_saved'));
    }

    /** Adds the standard intercompany rules (703/602 by counterparty; pairs 411-401 and 703-602) when they are missing. */
    public function quickIntercompany($id)
    {
        $group = FinConsolidationGroup::findOrFail($id);
        $existing = FinConsolidationRule::where('group_id', $group->id)->get();
        $added = 0;
        $sort = (int)$existing->max('sort');

        // eliminate_counterparty on 703/602
        $target = ['602', '703'];
        $hasCounterparty = $existing->where('type', 'eliminate_counterparty')->contains(function ($rule) use ($target) {
            $accounts = array_map('strval', (array)($rule->params['accounts'] ?? []));
            sort($accounts);

            return $accounts === $target;
        });

        if (!$hasCounterparty) {
            $this->createRule($group, 'eliminate_counterparty', ['accounts' => ['703', '602']], ++$sort);
            $added++;
        }

        // account pairs
        $normalize = fn($pair) => implode('|', collect($pair)->map(fn($c) => (string)$c)->sort()->all());
        $presentPairs = $existing->where('type', 'eliminate_account_pair')
            ->flatMap(fn($rule) => (array)($rule->params['pairs'] ?? []))
            ->filter(fn($p) => is_array($p) && count($p) === 2)
            ->map($normalize)->all();

        $missing = collect([['411', '401'], ['703', '602']])
            ->reject(fn($pair) => in_array($normalize($pair), $presentPairs, true))->values()->all();

        if ($missing) {
            $this->createRule($group, 'eliminate_account_pair', ['pairs' => $missing], ++$sort);
            $added += count($missing);
        }

        return Reply::success($added > 0
            ? __('databackd-analytics-consolidation-rules.messages.quick_added', ['count' => $added])
            : __('databackd-analytics-consolidation-rules.messages.quick_exists'));
    }

    // ------------------------------------------------------------------

    private function createRule(FinConsolidationGroup $group, string $type, array $params, int $sort): void
    {
        $rule = new FinConsolidationRule();
        $rule->company_id = company()->id;
        $rule->group_id = $group->id;
        $rule->type = $type;
        $rule->params = $params;
        $rule->sort = $sort;
        $rule->save();
    }

    private function validator(Request $request)
    {
        $request->merge(['base_currency' => strtoupper(trim((string)$request->input('base_currency')))]);

        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:191'],
            'base_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'method' => ['required', Rule::in(FinConsolidationGroup::METHODS)],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'base_currency.regex' => __('databackd-analytics-consolidation-rules.validation.currency_format'),
        ], __('databackd-analytics-consolidation-rules.attributes'));
    }

    private function fill(FinConsolidationGroup $group, Request $request): void
    {
        $group->name = trim($request->name);
        $group->base_currency = $request->base_currency;
        $group->method = $request->method;
        $group->eliminate_intercompany = $request->boolean('eliminate_intercompany');
        $group->minority_interest = $request->boolean('minority_interest');
        $group->description = filled($request->description) ? trim($request->description) : null;
    }

    /** Parses a decimal typed with a dot or a comma; empty = null. */
    private function decimal($value): ?float
    {
        $value = trim(str_replace(',', '.', (string)$value));

        return is_numeric($value) ? (float)$value : null;
    }

}

<?php

namespace App\Http\Controllers\Databackd\Analytics;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinConsolidationGroup;
use App\Models\Databackd\FinConsolidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * Consolidation rules of a group: create / edit / delete / reorder.
 *
 * `params` format (read by the consolidation engine):
 *   eliminate_counterparty  {"accounts": ["703","602"]}          empty = all P&L accounts + 411/401
 *   eliminate_account_pair  {"pairs": [["411","401"],["703","602"]]}
 *   reclassify              {"from": "453", "to": "454"}
 *   exclude_account         {"accounts": ["411", ...]}
 */
class ConsolidationRuleController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-analytics-consolidation-rules.settings_title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    /** Modal form for a new rule of the requested type. */
    public function create(Request $request, $groupId)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        $type = $request->query('type');
        $type = in_array($type, FinConsolidationRule::TYPES, true) ? $type : FinConsolidationRule::TYPES[0];

        return $this->form($group, new FinConsolidationRule(['type' => $type, 'params' => []]));
    }

    public function store(Request $request, $groupId)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        $type = (string)$request->input('type');

        if (!in_array($type, FinConsolidationRule::TYPES, true)) {
            return Reply::error(__('databackd-analytics-consolidation-rules.attributes.type'));
        }

        $result = $this->validated($request, $type);

        if (isset($result['reply'])) {
            return $result['reply'];
        }

        $rule = new FinConsolidationRule();
        $rule->company_id = company()->id;
        $rule->group_id = $group->id;
        $rule->type = $type;
        $rule->params = $result['params'];
        $rule->description = $result['description'];
        $rule->sort = (int)FinConsolidationRule::where('group_id', $group->id)->max('sort') + 1;
        $rule->save();

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.rule_created'));
    }

    public function edit($groupId, $id)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        $rule = FinConsolidationRule::where('group_id', $group->id)->findOrFail($id);

        return $this->form($group, $rule);
    }

    public function update(Request $request, $groupId, $id)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        $rule = FinConsolidationRule::where('group_id', $group->id)->findOrFail($id);

        // The type of an existing rule is fixed
        $result = $this->validated($request, $rule->type);

        if (isset($result['reply'])) {
            return $result['reply'];
        }

        $rule->params = $result['params'];
        $rule->description = $result['description'];
        $rule->save();

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.rule_updated'));
    }

    public function destroy($groupId, $id)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        FinConsolidationRule::where('group_id', $group->id)->findOrFail($id)->delete();

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.rule_deleted'));
    }

    /** Saves the drag & drop order: `order` = rule ids in the new order. */
    public function sort(Request $request, $groupId)
    {
        $group = FinConsolidationGroup::findOrFail($groupId);
        $ids = array_values(array_filter((array)$request->input('order'), fn($v) => is_numeric($v)));
        $rules = FinConsolidationRule::where('group_id', $group->id)->get()->keyBy('id');

        foreach ($ids as $index => $id) {
            if ($rule = $rules->get((int)$id)) {
                $rule->sort = $index + 1;
                $rule->save();
            }
        }

        return Reply::success(__('databackd-analytics-consolidation-rules.messages.rules_sorted'));
    }

    // ------------------------------------------------------------------

    /**
     * Human-readable summary of a rule as safe HTML (account codes are shown as badges with the account name as a tooltip).
     *
     * @param Collection $accountNames code => account name
     */
    public static function summaryHtml(FinConsolidationRule $rule, Collection $accountNames): string
    {
        $params = $rule->params ?? [];
        $badge = function ($code) use ($accountNames) {
            $code = (string)$code;

            return '<span class="badge badge-light f-12 font-weight-bold" title="' . e($accountNames->get($code, '')) . '">' . e($code) . '</span>';
        };
        $list = fn(array $codes) => implode(' ', array_map($badge, $codes));
        $text = fn(string $key) => e(__('databackd-analytics-consolidation-rules.summary_text.' . $key));

        switch ($rule->type) {
            case 'eliminate_counterparty':
                $accounts = array_values((array)($params['accounts'] ?? []));

                return $accounts
                    ? strtr($text('eliminate_counterparty'), [':accounts' => $list($accounts)])
                    : $text('eliminate_counterparty_all');

            case 'eliminate_account_pair':
                $pairs = [];
                foreach ((array)($params['pairs'] ?? []) as $pair) {
                    if (is_array($pair) && count($pair) === 2) {
                        $pairs[] = $badge($pair[0]) . ' &harr; ' . $badge($pair[1]);
                    }
                }

                return $pairs
                    ? strtr($text('eliminate_account_pair'), [':pairs' => implode('<span class="text-lightest mx-1">;</span> ', $pairs)])
                    : $text('incomplete');

            case 'reclassify':
                return !empty($params['from']) && !empty($params['to'])
                    ? strtr($text('reclassify'), [':from' => $badge($params['from']), ':to' => $badge($params['to'])])
                    : $text('incomplete');

            case 'exclude_account':
                $accounts = array_values((array)($params['accounts'] ?? []));

                return $accounts
                    ? strtr($text('exclude_account'), [':accounts' => $list($accounts)])
                    : $text('incomplete');
        }

        return $text('incomplete');
    }

    private function form(FinConsolidationGroup $group, FinConsolidationRule $rule)
    {
        $this->group = $group;
        $this->rule = $rule;
        $this->accounts = FinAccount::orderBy('code')->get(['id', 'code', 'name', 'is_active']);
        $params = $rule->params ?? [];

        $pairs = array_values(array_filter((array)($params['pairs'] ?? []), fn($p) => is_array($p) && count($p) === 2));
        $this->pairs = $pairs ?: [['', '']];

        return view('databackd.analytics.consolidation-rules.rule-form', $this->data);
    }

    /**
     * Validates the request for the given rule type.
     *
     * @return array{params: array, description: ?string}|array{reply: array}
     */
    private function validated(Request $request, string $type): array
    {
        $validator = Validator::make($request->all(), [
            'description' => ['nullable', 'string', 'max:500'],
        ], [], __('databackd-analytics-consolidation-rules.attributes'));

        if ($validator->fails()) {
            return ['reply' => Reply::formErrors($validator)];
        }

        $known = FinAccount::pluck('name', 'code')->mapWithKeys(fn($name, $code) => [(string)$code => $name]);
        $lang = 'databackd-analytics-consolidation-rules.validation.';
        $codes = fn($values) => array_values(array_unique(array_filter(
            array_map(fn($v) => trim((string)$v), (array)$values),
            fn($v) => $v !== ''
        )));
        $unknown = fn(array $values) => collect($values)->first(fn($code) => !$known->has($code));
        // AFV (S12): errors next to the field (accounts[] / pairs[...] / from / to), not only as a toast
        $fail = fn(string $message, string $field = 'accounts') => ['reply' => Reply::formErrors(
            Validator::make([], [])->after(fn($v) => $v->errors()->add($field, $message))
        )];

        switch ($type) {
            case 'eliminate_counterparty':
            case 'exclude_account':
                $accounts = $codes($request->input('accounts'));

                if ($type === 'exclude_account' && !$accounts) {
                    return $fail(__($lang . 'accounts_required'));
                }

                if ($bad = $unknown($accounts)) {
                    return $fail(__($lang . 'account_unknown', ['code' => e($bad)])); // rendered as HTML
                }

                $params = ['accounts' => $accounts];
                break;

            case 'eliminate_account_pair':
                $pairs = [];
                $seen = [];

                foreach ((array)$request->input('pairs') as $pair) {
                    $a = trim((string)($pair[0] ?? ''));
                    $b = trim((string)($pair[1] ?? ''));

                    if ($a === '' && $b === '') {
                        continue;
                    }

                    if ($a === '' || $b === '') {
                        return $fail(__($lang . 'pair_incomplete'), 'pairs');
                    }

                    if ($a === $b) {
                        return $fail(__($lang . 'pair_same'), 'pairs');
                    }

                    if ($bad = $unknown([$a, $b])) {
                        return $fail(__($lang . 'account_unknown', ['code' => e($bad)]), 'pairs'); // rendered as HTML
                    }

                    // The same pair in any order is stored once
                    $key = implode('|', collect([$a, $b])->sort()->all());

                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $pairs[] = [$a, $b];
                    }
                }

                if (!$pairs) {
                    return $fail(__($lang . 'pairs_required'), 'pairs');
                }

                $params = ['pairs' => $pairs];
                break;

            case 'reclassify':
                $from = trim((string)$request->input('from'));
                $to = trim((string)$request->input('to'));

                if ($from === '' || $to === '') {
                    return $fail(__($lang . 'pair_incomplete'), $from === '' ? 'from' : 'to');
                }

                if ($from === $to) {
                    return $fail(__($lang . 'reclassify_same'), 'to');
                }

                if ($bad = $unknown([$from, $to])) {
                    return $fail(__($lang . 'account_unknown', ['code' => e($bad)]), $bad === $from ? 'from' : 'to'); // rendered as HTML
                }

                // AFV (S12): the engine only moves amounts between accounts of the same type (revenue -> revenue, ...);
                // a rule across types was saved but silently did nothing
                $types = FinAccount::whereIn('code', [$from, $to])->pluck('type', 'code')->mapWithKeys(fn($t, $c) => [(string)$c => $t]);

                if (($types[$from] ?? null) !== ($types[$to] ?? null)) {
                    return $fail(__($lang . 'reclassify_type', [
                        'from' => __('databackd-analytics-consolidation.sections.' . ($types[$from] ?? 'asset')),
                        'to' => __('databackd-analytics-consolidation.sections.' . ($types[$to] ?? 'asset')),
                    ]), 'to');
                }

                $params = ['from' => $from, 'to' => $to];
                break;

            default:
                $params = [];
        }

        return [
            'params' => $params,
            'description' => filled($request->description) ? trim($request->description) : null,
        ];
    }

}

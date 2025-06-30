@php
    $L = 'databackd-analytics-consolidation-rules.';
    $isEdit = $rule->exists;
    $params = $rule->params ?? [];
    $typeName = __($L . 'type_names.' . $rule->type);
    $selectedAccounts = array_map('strval', (array)($params['accounts'] ?? []));
    $accountOption = function ($account, $selected) {
        $label = e($account->code . ' — ' . $account->name);
        return '<option value="' . e($account->code) . '" data-tokens="' . e($account->name) . '"'
            . ($selected ? ' selected' : '') . '>' . $label . '</option>';
    };
@endphp

<x-form id="rule-form" :method="$isEdit ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">
            @lang($isEdit ? $L . 'rule_form.edit_title' : $L . 'rule_form.add_title', ['type' => $typeName])
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            <input type="hidden" name="type" value="{{ $rule->type }}">
            <p class="f-13 text-lightest mb-0">@lang($L . 'type_hints.' . $rule->type)</p>

            @if ($rule->type === 'eliminate_counterparty' || $rule->type === 'exclude_account')
                @php($isExclude = $rule->type === 'exclude_account')
                <div class="row">
                    <div class="col-lg-12">
                        <label class="f-14 text-dark-grey mb-12 mt-3" for="rule_accounts">
                            @lang($isExclude ? $L . 'rule_form.accounts_exclude' : $L . 'rule_form.accounts_counterparty')
                            @if ($isExclude)<sup class="f-14 mr-1">*</sup>@endif
                        </label>
                        <div class="form-group mb-0">
                            <select name="accounts[]" id="rule_accounts" multiple class="form-control select-picker"
                                    data-live-search="true" data-size="8" data-actions-box="true"
                                    data-selected-text-format="count > 3"
                                    data-count-selected-text="@lang($L . 'rule_form.selected_count')"
                                    data-select-all-text="@lang($L . 'rule_form.select_all')"
                                    data-deselect-all-text="@lang($L . 'rule_form.deselect_all')"
                                    title="{{ $isExclude ? __($L . 'rule_form.select_account') : __($L . 'rule_form.accounts_all') }}">
                                @foreach ($accounts as $account)
                                    {!! $accountOption($account, in_array((string)$account->code, $selectedAccounts, true)) !!}
                                @endforeach
                            </select>
                        </div>
                        <small class="form-text text-muted">
                            @lang($isExclude ? $L . 'rule_form.accounts_exclude_help' : $L . 'rule_form.accounts_counterparty_help')
                        </small>
                    </div>
                </div>
            @elseif ($rule->type === 'eliminate_account_pair')
                <label class="f-14 text-dark-grey mb-12 mt-3">@lang($L . 'rule_form.pairs')<sup class="f-14 mr-1">*</sup></label>
                {{-- AFV (S12): .form-group so that a server error on "pairs" is shown under the pairs --}}
                <div class="form-group mb-0">
                <div id="pairs-wrapper">
                    @foreach ($pairs as $index => $pair)
                        <div class="row align-items-center mb-2 pair-row">
                            <div class="col-5">
                                <select name="pairs[{{ $index }}][0]" class="form-control select-picker" data-live-search="true"
                                        data-size="8" title="@lang($L . 'rule_form.pair_left')">
                                    @foreach ($accounts as $account)
                                        {!! $accountOption($account, (string)$pair[0] === (string)$account->code) !!}
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-auto px-0 text-lightest"><i class="fa fa-arrows-alt-h"></i></div>
                            <div class="col-5">
                                <select name="pairs[{{ $index }}][1]" class="form-control select-picker" data-live-search="true"
                                        data-size="8" title="@lang($L . 'rule_form.pair_right')">
                                    @foreach ($accounts as $account)
                                        {!! $accountOption($account, (string)$pair[1] === (string)$account->code) !!}
                                    @endforeach
                                </select>
                            </div>
                            <div class="col text-right">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-pair"
                                        title="@lang($L . 'rule_form.remove_pair')"><i class="fa fa-times"></i></button>
                            </div>
                        </div>
                    @endforeach
                </div>
                </div>
                <small class="form-text text-muted mb-2">@lang($L . 'rule_form.pairs_help')</small>
                <x-forms.button-secondary id="add-pair" icon="plus" class="btn-xs">@lang($L . 'rule_form.add_pair')</x-forms.button-secondary>

                <template id="pair-template">
                    <div class="row align-items-center mb-2 pair-row">
                        <div class="col-5">
                            <select name="pairs[__I__][0]" class="form-control select-picker" data-live-search="true"
                                    data-size="8" title="@lang($L . 'rule_form.pair_left')">
                                @foreach ($accounts as $account)
                                    {!! $accountOption($account, false) !!}
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto px-0 text-lightest"><i class="fa fa-arrows-alt-h"></i></div>
                        <div class="col-5">
                            <select name="pairs[__I__][1]" class="form-control select-picker" data-live-search="true"
                                    data-size="8" title="@lang($L . 'rule_form.pair_right')">
                                @foreach ($accounts as $account)
                                    {!! $accountOption($account, false) !!}
                                @endforeach
                            </select>
                        </div>
                        <div class="col text-right">
                            <button type="button" class="btn btn-sm btn-outline-danger remove-pair"
                                    title="@lang($L . 'rule_form.remove_pair')"><i class="fa fa-times"></i></button>
                        </div>
                    </div>
                </template>
            @elseif ($rule->type === 'reclassify')
                <div class="row">
                    <div class="col-lg-6">
                        <label class="f-14 text-dark-grey mb-12 mt-3" for="rule_from">@lang($L . 'rule_form.from')<sup class="f-14 mr-1">*</sup></label>
                        <div class="form-group mb-0">
                            <select name="from" id="rule_from" class="form-control select-picker" data-live-search="true"
                                    data-size="8" title="@lang($L . 'rule_form.select_account')">
                                @foreach ($accounts as $account)
                                    {!! $accountOption($account, (string)($params['from'] ?? '') === (string)$account->code) !!}
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <label class="f-14 text-dark-grey mb-12 mt-3" for="rule_to">@lang($L . 'rule_form.to')<sup class="f-14 mr-1">*</sup></label>
                        <div class="form-group mb-0">
                            <select name="to" id="rule_to" class="form-control select-picker" data-live-search="true"
                                    data-size="8" title="@lang($L . 'rule_form.select_account')">
                                @foreach ($accounts as $account)
                                    {!! $accountOption($account, (string)($params['to'] ?? '') === (string)$account->code) !!}
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endif

            <x-forms.textarea fieldId="rule_description" fieldName="description"
                              :fieldLabel="__($L . 'rule_form.description')"
                              :fieldPlaceholder="__($L . 'rule_form.description_placeholder')"
                              :fieldValue="$rule->description ?? ''"/>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">@lang($L . 'rule_form.close')</x-forms.button-cancel>
        <x-forms.button-primary id="save-rule" icon="check">@lang($L . 'rule_form.save')</x-forms.button-primary>
    </div>
</x-form>

<script>
    init(MODAL_LG);

    @if ($rule->type === 'eliminate_account_pair')
    (function () {
        var nextIndex = {{ count($pairs) }};

        $('#add-pair').on('click', function () {
            var html = document.getElementById('pair-template').innerHTML.replace(/__I__/g, nextIndex++);
            var $row = $(html);
            $('#pairs-wrapper').append($row);
            $row.find('.select-picker').selectpicker();
        });

        $('#pairs-wrapper').on('click', '.remove-pair', function () {
            var $rows = $('#pairs-wrapper .pair-row');

            if ($rows.length > 1) {
                $(this).closest('.pair-row').remove();
            } else {
                // Keep one empty row instead of an empty list
                $(this).closest('.pair-row').find('select').val('').selectpicker('refresh');
            }
        });
    })();
    @endif

    // AFV (S12): a changed field drops its old error; "from" and "to" must differ (checked on change)
    $('#rule-form').on('change', 'select', function () {
        var $group = $(this).closest('.form-group');
        $group.find('.invalid-feedback').remove();
        $group.find('.is-invalid').removeClass('is-invalid');

        @if ($rule->type === 'reclassify')
        var from = $('#rule_from').val();
        var to = $('#rule_to').val();
        var $toGroup = $('#rule_to').closest('.form-group');
        $toGroup.find('.invalid-feedback').remove();
        $toGroup.find('.is-invalid').removeClass('is-invalid');

        if (from && to && from === to) {
            $toGroup.append($('<div class="d-block invalid-feedback">').text(@json(__($L . 'validation.reclassify_same'))));
            $toGroup.find('.bootstrap-select, select').addClass('is-invalid');
        }
        @endif
    });

    $('#save-rule').click(function () {
        $.easyAjax({
            url: "{{ $isEdit ? route('databackd.analytics.consolidation-rules.rules.update', [$group->id, $rule->id]) : route('databackd.analytics.consolidation-rules.rules.store', $group->id) }}",
            container: '#rule-form',
            type: "POST",
            blockUI: true,
            disableButton: true,
            buttonSelector: "#save-rule",
            data: $('#rule-form').serialize(),
            success: function (response) {
                if (response.status == 'success') {
                    window.location.reload();
                }
            }
        });
    });
</script>

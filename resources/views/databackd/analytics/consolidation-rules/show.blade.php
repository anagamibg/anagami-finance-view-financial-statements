@extends('layouts.app')

@push('styles')
    <style>
        .dbk-ent-row.is-off > td { background: var(--dbk-surface-2); }
        .dbk-ent-row.is-off .dbk-ent-name { color: var(--dbk-text-muted); }
        .dbk-ent-input { max-width: 130px; }
        .dbk-rule-handle { cursor: move; color: var(--dbk-text-muted); width: 34px; }
        .dbk-rule-row:hover .dbk-rule-handle { color: var(--dbk-accent); }
        .dbk-rule-placeholder { height: 52px; background: var(--dbk-accent-soft); outline: 1px dashed var(--dbk-accent); }
        .dbk-rule-row.ui-sortable-helper { background: var(--dbk-surface); box-shadow: var(--dbk-shadow); display: table; }
        .dbk-rule-summary .badge { margin: 0 1px; }
        .dbk-cg-note { font-size: 12px; color: var(--dbk-text-muted); }
    </style>
@endpush

@section('content')
    @php
        $L = 'databackd-analytics-consolidation-rules.';
        $fmtPct = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    @endphp

    <div class="content-wrapper dbk-analytics">
        {{-- Group summary --}}
        <x-dbk.page-header :title="$group->name" :subtitle="$group->description">
            @if ($group->eliminate_intercompany || $group->minority_interest)
                <div class="dbk-analytics-detail-tags">
                    @if ($group->eliminate_intercompany)
                        <span class="dbk-badge is-good">@lang($L . 'flags.eliminate_intercompany')</span>
                    @endif
                    @if ($group->minority_interest)
                        <span class="dbk-badge is-info">@lang($L . 'flags.minority_interest')</span>
                    @endif
                </div>
            @endif
            <x-slot name="actions">
                <x-forms.link-secondary :link="route('databackd.analytics.consolidation-rules.index')" icon="arrow-left">
                    @lang($L . 'actions.back')
                </x-forms.link-secondary>
                <x-forms.button-secondary class="edit-group" icon="edit">@lang($L . 'actions.edit')</x-forms.button-secondary>
                @if ($consolidationUrl)
                    <x-forms.link-primary :link="$consolidationUrl" icon="table">
                        @lang($L . 'actions.open_consolidation')
                    </x-forms.link-primary>
                @endif
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-kpi-grid dbk-cg-kpis">
            <x-dbk.kpi :label="__($L . 'group_info.method')" :value="__('databackd-analytics-common.methods.' . $group->method)" icon="bi-layers" />
            <x-dbk.kpi :label="__($L . 'group_info.currency')" :value="$group->base_currency" icon="bi-currency-euro" />
            <x-dbk.kpi :label="__($L . 'group_info.entities')" :value="__($L . 'entities_count', ['included' => $includedCount, 'total' => $memberships->count()])" icon="bi-building" />
            <x-dbk.kpi :label="__($L . 'group_info.rules')" :value="$rules->count()" icon="bi-shuffle" />
        </div>

        {{-- Legal entities --}}
        <div class="dbk-card mb-4">
            <div class="dbk-card-header has-border">
                <div style="min-width: 0">
                    <h3 class="dbk-card-title">@lang($L . 'entities.title')</h3>
                    <p class="dbk-card-subtitle">@lang($L . 'entities.help')</p>
                </div>
            </div>

            @if ($entities->isEmpty())
                <x-dbk.empty icon="building" :title="__($L . 'entities.empty')" :text="__($L . 'entities.empty_hint')">
                    @if ($entitiesUrl)
                        <x-forms.link-secondary :link="$entitiesUrl" icon="sitemap">@lang($L . 'entities.manage_entities')</x-forms.link-secondary>
                    @endif
                </x-dbk.empty>
            @else
                <x-form id="entities-form" method="PUT">
                    {{-- AFV (S12): number of invalid fields (blur validation) --}}
                    <div class="dbk-cons-notice is-warning mx-3 mt-3 d-none" id="entities-errors" role="alert"></div>
                    <div class="dbk-table-wrap is-flush">
                        <table class="dbk-table dbk-ent-table" id="entities-table">
                            <thead>
                                <tr>
                                <th style="width: 90px">@lang($L . 'entities.in_group')</th>
                                <th>@lang($L . 'entities.entity')</th>
                                <th>@lang($L . 'entities.currency')</th>
                                <th>@lang($L . 'entities.include')</th>
                                <th>@lang($L . 'entities.ownership')</th>
                                <th>@lang($L . 'entities.fx_rate')</th>
                                </tr>
                            </thead>
                            <tbody>

                            @foreach ($entities as $entity)
                                @php
                                    $member = $memberships->get($entity->id);
                                    $isMember = (bool)$member;
                                    $sameCurrency = strtoupper($entity->currency) === strtoupper($group->base_currency);
                                    $key = 'entities[' . $entity->id . ']';
                                @endphp
                                <tr class="dbk-ent-row @unless($isMember) is-off @endunless" data-entity="{{ $entity->id }}">
                                    <td data-label="{{ __($L . 'entities.in_group') }}" class="dbk-ent-in-group">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input ent-member" value="1"
                                                   name="{{ $key }}[member]" id="ent_member_{{ $entity->id }}" @checked($isMember)>
                                            <label class="custom-control-label cursor-pointer" for="ent_member_{{ $entity->id }}"></label>
                                        </div>
                                    </td>
                                    <td data-label="{{ __($L . 'entities.entity') }}" class="dbk-ent-entity">
                                        <div class="f-w-500 dbk-ent-name">{{ $entity->name }}
                                            @unless ($entity->is_active)
                                                <span class="badge badge-light f-11 ml-1">@lang($L . 'entities.inactive')</span>
                                            @endunless
                                        </div>
                                        @if ($entity->eik)
                                            <div class="dbk-cg-note">@lang($L . 'entities.country_eik', ['eik' => $entity->eik])</div>
                                        @endif
                                    </td>
                                    <td data-label="{{ __($L . 'entities.currency') }}" class="dbk-ent-currency"><span class="dbk-chip">{{ $entity->currency }}</span></td>
                                    <td data-label="{{ __($L . 'entities.include') }}" class="dbk-ent-include">
                                        <div class="custom-control custom-switch">
                                            <input type="checkbox" class="custom-control-input ent-field" value="1"
                                                   name="{{ $key }}[include]" id="ent_include_{{ $entity->id }}"
                                                   @checked($isMember && $member->include) @disabled(!$isMember)>
                                            <label class="custom-control-label cursor-pointer" for="ent_include_{{ $entity->id }}"></label>
                                        </div>
                                    </td>
                                    <td data-label="{{ __($L . 'entities.ownership') }}" class="dbk-ent-ownership">
                                        <div class="input-group input-group-sm dbk-ent-input">
                                            <input type="number" step="0.01" min="0" max="100" class="form-control f-14 ent-field ent-pct" inputmode="decimal"
                                                   aria-label="{{ $entity->name }}: @lang($L . 'entities.ownership')"
                                                   name="{{ $key }}[ownership_pct]" id="ent_pct_{{ $entity->id }}"
                                                   value="{{ $isMember && $member->ownership_pct !== null ? $fmtPct($member->ownership_pct) : '' }}"
                                                   placeholder="{{ $fmtPct($entity->ownership_pct) }}"
                                                   title="@lang($L . 'entities.ownership_default', ['pct' => $fmtPct($entity->ownership_pct)])"
                                                   @disabled(!$isMember)>
                                            <div class="input-group-append"><span class="input-group-text f-13">%</span></div>
                                        </div>
                                    </td>
                                    <td data-label="{{ __($L . 'entities.fx_rate') }}" class="dbk-ent-fx-rate">
                                        @if ($sameCurrency)
                                            <span class="dbk-cg-note">@lang($L . 'entities.fx_same_currency', ['currency' => $group->base_currency])</span>
                                        @else
                                            <div class="input-group input-group-sm dbk-ent-input" style="max-width: 190px">
                                                <input type="number" step="0.000001" min="0" class="form-control f-14 ent-field ent-fx" inputmode="decimal"
                                                       aria-label="{{ $entity->name }}: @lang($L . 'entities.fx_rate')"
                                                       name="{{ $key }}[fx_rate]" id="ent_fx_{{ $entity->id }}"
                                                       value="{{ $isMember && $member->fx_rate !== null ? rtrim(rtrim(number_format($member->fx_rate, 6, '.', ''), '0'), '.') : '' }}"
                                                       placeholder="@lang($L . 'entities.fx_placeholder')"
                                                       title="@lang($L . 'entities.fx_help', ['currency' => $group->base_currency])"
                                                       @disabled(!$isMember)>
                                                <div class="input-group-append"><span class="input-group-text f-12">{{ $entity->currency }} → {{ $group->base_currency }}</span></div>
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="dbk-card-footer">
                        <x-forms.button-primary id="save-entities" icon="check">@lang($L . 'entities.save')</x-forms.button-primary>
                    </div>
                </x-form>
            @endif
        </div>

        {{-- Rules --}}
        <div class="dbk-card mb-4">
            <div class="dbk-card-header has-border flex-wrap">
                <div style="min-width: 0">
                    <h3 class="dbk-card-title">@lang($L . 'rules.title')</h3>
                    <p class="dbk-card-subtitle">@lang($L . 'rules.help')</p>
                </div>
                <div class="dbk-card-actions flex-wrap">
                    <x-forms.button-secondary id="quick-intercompany" icon="magic"
                                              title="{{ __($L . 'rules.quick_hint') }}">
                        @lang($L . 'rules.quick')
                    </x-forms.button-secondary>
                    <div class="dropdown">
                        <button class="btn btn-primary rounded f-14 p-2 dropdown-toggle" type="button" id="add-rule-menu"
                                data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-plus mr-1"></i>@lang($L . 'rules.add')
                        </button>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="add-rule-menu">
                            @foreach ($ruleTypes as $type)
                                <a class="dropdown-item f-14 add-rule" href="javascript:;" data-type="{{ $type }}">
                                    <div>@lang($L . 'type_names.' . $type)</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            @unless ($group->eliminate_intercompany)
                <div class="dbk-cons-notice is-info mx-3 mt-3" role="status">
                    <i class="bi bi-info-circle" aria-hidden="true"></i><div>@lang($L . 'help.intercompany_off')</div>
                </div>
            @endunless

            @if ($rules->isEmpty())
                <x-dbk.empty icon="shuffle" :title="__($L . 'rules.empty')" :text="__($L . 'rules.empty_hint')" />
            @else
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-rule-table" id="rules-table">
                            <thead>
                                <tr>
                            <th style="width: 34px"></th>
                            <th style="width: 40px">@lang($L . 'rules.order')</th>
                            <th style="width: 220px">@lang($L . 'rules.type')</th>
                            <th>@lang($L . 'rules.rule')</th>
                            <th class="num" style="width: 110px"></th>
                                </tr>
                            </thead>
                            <tbody>

                        @foreach ($rules as $index => $rule)
                            <tr class="dbk-rule-row" data-id="{{ $rule->id }}">
                                <td class="dbk-rule-handle text-center" title="@lang($L . 'rules.drag')"><i class="fa fa-grip-vertical"></i></td>
                                <td class="rule-number dbk-text-muted">{{ $index + 1 }}</td>
                                <td>
                                    <span class="dbk-badge no-dot">@lang($L . 'type_names.' . $rule->type)</span>
                                </td>
                                <td class="dbk-rule-summary">
                                    <div>{!! $ruleSummaries[$rule->id] !!}</div>
                                    @if ($rule->description)
                                        <div class="dbk-cg-note mt-1">{{ $rule->description }}</div>
                                    @endif
                                </td>
                                <td class="num dbk-analytics-actions dbk-rule-actions">
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-rule"
                                            data-id="{{ $rule->id }}" title="@lang($L . 'rules.edit')" aria-label="@lang($L . 'rules.edit')"><i class="fa fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-rule ml-1"
                                            data-id="{{ $rule->id }}" title="@lang($L . 'rules.delete')" aria-label="@lang($L . 'rules.delete')"><i class="fa fa-trash"></i></button>
                                </td>
                            </tr>
                        @endforeach
                            </tbody>
                        </table>
                </div>
            @endif
        </div>

        {{-- AFV (S12): how the rules work, with an example --}}
        <div class="dbk-card mb-4" id="rules-help">
            <details class="dbk-cons-explainer is-card" @if ($rules->isEmpty()) open @endif>
                <summary>@lang($L . 'help.title')</summary>
                <p>@lang($L . 'help.intro')</p>
                <ul class="dbk-cons-help-list">
                    @foreach ($ruleTypes as $type)
                        <li><strong>@lang($L . 'type_names.' . $type)</strong> — @lang($L . 'help.items.' . $type)</li>
                    @endforeach
                </ul>
                <p class="dbk-cons-example"><strong>@lang($L . 'help.example_title'):</strong> @lang($L . 'help.example')</p>
                <p class="mb-0 dbk-text-muted">@lang($L . 'help.requirement')</p>
            </details>
        </div>
    </div>
@endsection

@push('scripts')
    <script>var dbkBootstrapPlugins = {tooltip: $.fn.tooltip, button: $.fn.button};</script>
    <script src="{{ asset('vendor/jquery/jquery-ui.min.js') }}"></script>
    <script>
        // jQuery UI overrides Bootstrap's tooltip/button plugins: restore them
        $.widget.bridge('uitooltip', $.ui.tooltip);
        $.fn.tooltip = dbkBootstrapPlugins.tooltip;
        $.fn.button = dbkBootstrapPlugins.button;
    </script>
    <script>
        var groupId = {{ $group->id }};
        var rulesUrl = "{{ route('databackd.analytics.consolidation-rules.rules.create', $group->id) }}";

        // ----- Group edit -----
        $('body').on('click', '.edit-group', function () {
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, "{{ route('databackd.analytics.consolidation-rules.edit', $group->id) }}");
        });

        // ----- Legal entities -----
        $('.ent-member').on('change', function () {
            var $row = $(this).closest('tr');
            var on = $(this).is(':checked');

            $row.toggleClass('is-off', !on);
            $row.find('.ent-field').prop('disabled', !on);

            if (!on) {
                $row.find('.is-invalid').removeClass('is-invalid');
                $row.find('.invalid-feedback').remove();
            }

            if (on) {
                $row.find('input[name$="[include]"]').prop('checked', true);
            }
        });

        // AFV (S12): validation on leaving the field, the error text next to the field
        var entityMessages = {
            pct: @json(__($L . 'validation.ownership_range')),
            fx: @json(__($L . 'validation.fx_positive')),
            count: @json(__($L . 'validation.errors_count', ['count' => ':count']))
        };

        function entityFieldError($input) {
            var raw = $.trim($input.val());
            var bad = $input[0].validity && $input[0].validity.badInput;

            if ($input.prop('disabled') || (raw === '' && !bad)) {
                return null;
            }

            var value = parseFloat(raw.replace(',', '.'));

            if ($input.hasClass('ent-pct')) {
                return (bad || isNaN(value) || value < 0 || value > 100) ? entityMessages.pct : null;
            }

            return (bad || isNaN(value) || value <= 0) ? entityMessages.fx : null;
        }

        function showEntityFieldError($input) {
            var message = entityFieldError($input);
            var $group = $input.closest('.input-group');
            var errorId = $input.attr('id') + '-error';

            $group.find('.invalid-feedback').remove();
            $input.toggleClass('is-invalid', !!message).attr('aria-invalid', message ? 'true' : null);

            if (message) {
                $group.append($('<div class="invalid-feedback d-block">').attr('id', errorId).text(message));
                $input.attr('aria-describedby', errorId);
            } else {
                $input.removeAttr('aria-describedby');
            }

            return !message;
        }

        $('#entities-table').on('blur', '.ent-pct, .ent-fx', function () {
            showEntityFieldError($(this));
        });

        $('#save-entities').on('click', function () {
            var $invalid = $('#entities-table .ent-pct, #entities-table .ent-fx').filter(function () {
                return !showEntityFieldError($(this));
            });

            $('#entities-errors').toggleClass('d-none', $invalid.length === 0)
                .text(entityMessages.count.replace(':count', $invalid.length));

            if ($invalid.length) {
                $invalid.first().trigger('focus');

                return;
            }

            $.easyAjax({
                url: "{{ route('databackd.analytics.consolidation-rules.entities.update', $group->id) }}",
                container: '#entities-form',
                type: "POST",
                blockUI: true,
                disableButton: true,
                buttonSelector: "#save-entities",
                data: $('#entities-form').serialize(),
                success: function (response) {
                    if (response.status == 'success') {
                        window.location.reload();
                    }
                }
            });
        });

        // ----- Rules -----
        $('body').on('click', '.add-rule', function () {
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, rulesUrl + '?type=' + $(this).data('type'));
        });

        $('body').on('click', '.edit-rule', function () {
            var url = "{{ route('databackd.analytics.consolidation-rules.rules.edit', [$group->id, ':id']) }}".replace(':id', $(this).data('id'));
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.delete-rule', function () {
            var id = $(this).data('id');

            Swal.fire({
                title: @json(__($L . 'confirm_title')),
                text: @json(__($L . 'rules.delete_confirm')),
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: @json(__($L . 'confirm_delete')),
                cancelButtonText: @json(__($L . 'cancel')),
                customClass: {
                    confirmButton: 'btn btn-primary mr-3',
                    cancelButton: 'btn btn-secondary'
                },
                showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                buttonsStyling: false
            }).then(function (result) {
                if (!result.isConfirmed) {
                    return;
                }

                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.analytics.consolidation-rules.rules.destroy', [$group->id, ':id']) }}".replace(':id', id),
                    blockUI: true,
                    data: {'_token': '{{ csrf_token() }}', '_method': 'DELETE'},
                    success: function (response) {
                        if (response.status == 'success') {
                            window.location.reload();
                        }
                    }
                });
            });
        });

        $('#quick-intercompany').on('click', function () {
            $.easyAjax({
                type: 'POST',
                url: "{{ route('databackd.analytics.consolidation-rules.quick-intercompany', $group->id) }}",
                blockUI: true,
                disableButton: true,
                buttonSelector: "#quick-intercompany",
                data: {'_token': '{{ csrf_token() }}'},
                success: function (response) {
                    if (response.status == 'success') {
                        window.location.reload();
                    }
                }
            });
        });

        // Drag & drop order
        $('#rules-table tbody').sortable({
            handle: '.dbk-rule-handle',
            axis: 'y',
            items: 'tr.dbk-rule-row',
            placeholder: 'dbk-rule-placeholder',
            helper: function (e, tr) {
                // Keep the column widths while dragging
                var $cells = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function (i) {
                    $(this).width($cells.eq(i).width());
                });
                return $helper;
            },
            update: function () {
                var order = [];

                $('#rules-table tr.dbk-rule-row').each(function (index) {
                    order.push($(this).data('id'));
                    $(this).find('.rule-number').text(index + 1);
                });

                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.analytics.consolidation-rules.rules.sort', $group->id) }}",
                    data: {'_token': '{{ csrf_token() }}', 'order': order}
                });
            }
        });
    </script>
@endpush

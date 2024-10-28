@extends('layouts.app')

@push('styles')
    <style>
        .rb-formula-input {
            font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: 14px; line-height: 1.6;
            background: var(--dbk-surface-2); min-height: 110px; resize: vertical;
        }
        .rb-toolbar { background: var(--dbk-surface-2); border: 1px solid var(--dbk-border); border-radius: var(--dbk-radius-sm); padding: 14px 14px 6px; }
        .rb-toolbar-title { font-size: 12px; font-weight: 500; color: var(--dbk-text-muted); text-transform: uppercase; margin-bottom: 8px; }
        .rb-toolbar label.rb-lbl { font-size: 12px; color: var(--dbk-text-muted); margin-bottom: 3px; }
        .rb-toolbar .bootstrap-select { width: 100% !important; }
        .rb-toolbar .btn-op { min-width: 38px; font-weight: 600; font-size: 15px; }
        .rb-toolbar #rb_mode .btn { white-space: nowrap; padding-left: 8px; padding-right: 8px; }
        .rb-toolbar .btn-const, .rb-toolbar .btn-func { font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
        .rb-ins-row { display: flex; align-items: flex-end; }
        .rb-ins-row .rb-grow { flex: 1 1 auto; min-width: 0; }
        .rb-result { font-size: 30px; font-weight: 600; line-height: 1.1; color: var(--dbk-text); font-variant-numeric: tabular-nums; }
        .rb-result.is-null { color: var(--dbk-text-muted); }
        .rb-comp-table th { font-size: 12px; font-weight: 500; color: var(--dbk-text-muted); text-transform: uppercase; border-top: 0; }
        .rb-comp-table td { font-size: 13px; vertical-align: middle; }
        .rb-comp-table code { font-size: 11px; color: var(--dbk-text-muted); }
        .rb-errors { margin: 0; padding-left: 18px; }
        .rb-example { border: 1px solid var(--dbk-border); border-radius: var(--dbk-radius-sm); padding: 10px 12px; margin-bottom: 8px; }
        .rb-example code { font-size: 12px; color: var(--dbk-text); word-break: break-all; }
        .rb-syntax td { font-size: 13px; vertical-align: top; }
        .rb-syntax td:first-child { white-space: nowrap; font-family: SFMono-Regular, Menlo, Consolas, monospace; color: var(--dbk-accent); }
        .rb-help-toggle .fa-chevron-down { transition: transform .15s ease; }
        .rb-help-toggle.collapsed .fa-chevron-down { transform: rotate(-90deg); }
    </style>
@endpush

@section('content')
    @php
        $isEdit = $ratio->exists;
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-form id="save-ratio-data-form" :method="$isEdit ? 'PUT' : 'POST'">
            <div class="row">
                {{-- Basic data --}}
                <div class="col-lg-5 mb-3">
                    <div class="dbk-card dbk-analytics-form-card h-100">
                        <h4 class="dbk-analytics-form-head">
                            {{ __('databackd-analytics-builder.form.section_basic') }}
                        </h4>

                        <div class="dbk-analytics-form-body">
                            <x-forms.text :fieldLabel="__('databackd-analytics-builder.form.name')" fieldName="name"
                                fieldId="ratio_name" fieldRequired="true" :fieldValue="$ratio->name"
                                :fieldPlaceholder="__('databackd-analytics-builder.form.name_placeholder')" />

                            <div class="form-group my-3">
                                <x-forms.label fieldId="ratio_code" :fieldLabel="__('databackd-analytics-builder.form.code')" />
                                <input type="text" class="form-control height-35 f-14 rb-code-input" id="ratio_code" readonly
                                    style="font-family:SFMono-Regular,Menlo,Consolas,monospace"
                                    value="{{ $isEdit ? $ratio->code : '' }}"
                                    placeholder="{{ __('databackd-analytics-builder.form.code_pending') }}">
                                <small class="form-text text-muted">
                                    {{ $isEdit ? __('databackd-analytics-builder.form.code_help_edit') : __('databackd-analytics-builder.form.code_help_create') }}
                                </small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <x-forms.select fieldId="ratio_group" fieldName="group"
                                        :fieldLabel="__('databackd-analytics-builder.form.group')" fieldRequired="true">
                                        @foreach (\App\Models\Databackd\FinRatio::GROUPS as $group)
                                            <option value="{{ $group }}" @selected($ratio->group === $group)>
                                                {{ __('databackd-analytics-common.ratio_groups.' . $group) }}</option>
                                        @endforeach
                                    </x-forms.select>
                                </div>
                                <div class="col-md-6">
                                    <x-forms.select fieldId="ratio_format" fieldName="format"
                                        :fieldLabel="__('databackd-analytics-builder.form.format')" fieldRequired="true">
                                        @foreach (\App\Models\Databackd\FinRatio::FORMATS as $format)
                                            <option value="{{ $format }}" @selected($ratio->format === $format)>
                                                {{ __('databackd-analytics-common.ratio_formats.' . $format) }}</option>
                                        @endforeach
                                    </x-forms.select>
                                </div>
                                <div class="col-md-12">
                                    <small class="form-text text-muted">{{ __('databackd-analytics-builder.form.format_help') }}</small>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group my-3">
                                        <x-forms.label fieldId="ratio_target_min" :fieldLabel="__('databackd-analytics-builder.form.target_min')" />
                                        <input type="number" step="any" class="form-control height-35 f-14" name="target_min"
                                            id="ratio_target_min" value="{{ $ratio->target_min }}">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group my-3">
                                        <x-forms.label fieldId="ratio_target_max" :fieldLabel="__('databackd-analytics-builder.form.target_max')" />
                                        <input type="number" step="any" class="form-control height-35 f-14" name="target_max"
                                            id="ratio_target_max" value="{{ $ratio->target_max }}">
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <small class="form-text text-muted mt-0">{{ __('databackd-analytics-builder.form.target_help') }}</small>
                                </div>
                            </div>

                            <x-forms.toggle-switch :fieldLabel="__('databackd-analytics-builder.form.higher_is_better')"
                                fieldName="higher_is_better" fieldId="ratio_higher_is_better"
                                :checked="(bool)$ratio->higher_is_better"
                                :fieldHelp="__('databackd-analytics-builder.form.higher_is_better_help')" />

                            <x-forms.toggle-switch :fieldLabel="__('databackd-analytics-builder.form.is_active')"
                                fieldName="is_active" fieldId="ratio_is_active" :checked="(bool)$ratio->is_active"
                                :fieldHelp="__('databackd-analytics-builder.form.is_active_help')" />

                            <x-forms.textarea :fieldLabel="__('databackd-analytics-builder.form.description')"
                                fieldName="description" fieldId="ratio_description" :fieldValue="$ratio->description"
                                :fieldPlaceholder="__('databackd-analytics-builder.form.description_placeholder')" />
                        </div>
                    </div>
                </div>

                {{-- Formula editor and live preview --}}
                <div class="col-lg-7 mb-3">
                    <div class="dbk-card dbk-analytics-form-card mb-3">
                        <h4 class="dbk-analytics-form-head">
                            {{ __('databackd-analytics-builder.form.section_formula') }}
                        </h4>

                        <div class="dbk-analytics-form-body">
                            <div class="form-group my-3">
                                <x-forms.label fieldId="ratio_formula" :fieldLabel="__('databackd-analytics-builder.form.formula')" fieldRequired="true" />
                                <div class="dbk-analytics-ac">
                                    <textarea class="form-control rb-formula-input" name="formula" id="ratio_formula" rows="4"
                                        spellcheck="false" autocomplete="off" autocapitalize="off"
                                        role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="rb_ac_list"
                                        aria-describedby="rb_formula_help"
                                        placeholder="{{ __('databackd-analytics-builder.form.formula_placeholder') }}">{{ $ratio->formula }}</textarea>
                                    <ul class="dbk-analytics-ac-list" id="rb_ac_list" role="listbox" hidden></ul>
                                </div>
                                <small class="form-text text-muted" id="rb_formula_help">{{ __('databackd-analytics-builder.form.formula_help') }}
                                    {{ __('databackd-analytics-builder.form.autocomplete_help') }}</small>
                            </div>

                            <div class="rb-toolbar mb-3">
                                <div class="rb-toolbar-title">{{ __('databackd-analytics-builder.editor.insert_title') }}</div>

                                {{-- Account --}}
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <label class="rb-lbl" for="rb_account">{{ __('databackd-analytics-builder.editor.account') }}</label>
                                        <select id="rb_account" class="form-control select-picker" data-live-search="true" data-size="8"
                                            title="{{ __('databackd-analytics-builder.editor.account_placeholder') }}">
                                            @foreach ($accounts as $account)
                                                <option value="{{ $account->code }}">{{ $account->code }} - {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3 col-sm-6 col-12 mb-2">
                                        <label class="rb-lbl" for="rb_mask" data-toggle="tooltip"
                                            data-original-title="{{ __('databackd-analytics-builder.editor.mask_help') }}">{{ __('databackd-analytics-builder.editor.mask') }}</label>
                                        <input type="text" id="rb_mask" class="form-control height-35 f-14"
                                            placeholder="{{ __('databackd-analytics-builder.editor.mask_placeholder') }}">
                                    </div>
                                    <div class="col-md-3 col-sm-6 col-12 mb-2">
                                        <label class="rb-lbl" data-toggle="tooltip"
                                            data-original-title="{{ __('databackd-analytics-builder.editor.mode_help') }}">
                                            {{ __('databackd-analytics-builder.editor.mode_balance') }} / {{ __('databackd-analytics-builder.editor.mode_flow') }}</label>
                                        <div class="btn-group d-flex" id="rb_mode" role="group">
                                            <button type="button" class="btn btn-secondary f-13 active" data-mode="ACC">{{ __('databackd-analytics-builder.editor.mode_balance') }}</button>
                                            <button type="button" class="btn btn-secondary f-13" data-mode="FLOW">{{ __('databackd-analytics-builder.editor.mode_flow') }}</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="btn btn-primary btn-sm rounded f-13" id="rb_add_account">
                                        <i class="fa fa-plus mr-1"></i>{{ __('databackd-analytics-builder.editor.insert') }}: {{ __('databackd-analytics-builder.editor.account') }}
                                    </button>
                                </div>

                                {{-- Type / subtype / ratio --}}
                                <div class="row">
                                    <div class="col-md-4 mb-2">
                                        <label class="rb-lbl" for="rb_type">{{ __('databackd-analytics-builder.editor.type') }}</label>
                                        <div class="rb-ins-row">
                                            <div class="rb-grow">
                                                <select id="rb_type" class="form-control select-picker" data-size="8"
                                                    title="{{ __('databackd-analytics-builder.editor.choose') }}">
                                                    @foreach ($types as $type)
                                                        <option value="{{ $type }}">{{ __('databackd-analytics-common.account_types.' . $type) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button" class="btn btn-secondary ml-1 f-13" id="rb_add_type"
                                                data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.insert') }}"><i class="fa fa-plus"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="rb-lbl" for="rb_subtype">{{ __('databackd-analytics-builder.editor.subtype') }}</label>
                                        <div class="rb-ins-row">
                                            <div class="rb-grow">
                                                <select id="rb_subtype" class="form-control select-picker" data-size="8" data-live-search="true"
                                                    title="{{ __('databackd-analytics-builder.editor.choose') }}">
                                                    @foreach ($subtypes as $subtype)
                                                        <option value="{{ $subtype }}">{{ __('databackd-analytics-common.account_subtypes.' . $subtype) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button" class="btn btn-secondary ml-1 f-13" id="rb_add_subtype"
                                                data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.insert') }}"><i class="fa fa-plus"></i></button>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <label class="rb-lbl" for="rb_ref">{{ __('databackd-analytics-builder.editor.ratio') }}</label>
                                        <div class="rb-ins-row">
                                            <div class="rb-grow">
                                                <select id="rb_ref" class="form-control select-picker" data-size="8" data-live-search="true"
                                                    title="{{ __('databackd-analytics-builder.editor.choose') }}">
                                                    @foreach ($otherRatios as $other)
                                                        <option value="{{ $other->code }}">{{ $other->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button" class="btn btn-secondary ml-1 f-13" id="rb_add_ref"
                                                data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.insert') }}"><i class="fa fa-plus"></i></button>
                                        </div>
                                    </div>
                                </div>

                                {{-- Operators, constants, AVG --}}
                                <div class="d-flex flex-wrap align-items-center mb-2 mt-1">
                                    <div class="btn-group mr-3 mb-2" role="group" aria-label="{{ __('databackd-analytics-builder.editor.operators') }}">
                                        <button type="button" class="btn btn-light border btn-op" data-op="+">+</button>
                                        <button type="button" class="btn btn-light border btn-op" data-op="-">−</button>
                                        <button type="button" class="btn btn-light border btn-op" data-op="*">×</button>
                                        <button type="button" class="btn btn-light border btn-op" data-op="/">÷</button>
                                        <button type="button" class="btn btn-light border btn-op" data-op="(">(</button>
                                        <button type="button" class="btn btn-light border btn-op" data-op=")">)</button>
                                    </div>
                                    <div class="btn-group mr-3 mb-2" role="group" aria-label="{{ __('databackd-analytics-builder.editor.functions') }}">
                                        @foreach (['POS', 'ABS', 'MIN', 'MAX'] as $fn)
                                            <button type="button" class="btn btn-light border btn-func" data-func="{{ $fn }}"
                                                data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.func_' . strtolower($fn)) }}">{{ $fn }}( )</button>
                                        @endforeach
                                    </div>
                                    <div class="btn-group mr-3 mb-2" role="group" aria-label="{{ __('databackd-analytics-builder.editor.constants') }}">
                                        <button type="button" class="btn btn-light border btn-const" data-const="DAYS"
                                            data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.const_days') }}">DAYS</button>
                                        <button type="button" class="btn btn-light border btn-const" data-const="ANNUAL"
                                            data-toggle="tooltip" data-original-title="{{ __('databackd-analytics-builder.editor.const_annual') }}">ANNUAL</button>
                                        <button type="button" class="btn btn-light border btn-const" data-const="100">100</button>
                                    </div>
                                    <div class="custom-control custom-switch mr-3 mb-2" data-toggle="tooltip"
                                        data-original-title="{{ __('databackd-analytics-builder.editor.avg_help') }}">
                                        <input type="checkbox" class="custom-control-input" id="rb_avg">
                                        <label class="custom-control-label cursor-pointer f-13" for="rb_avg">AVG(...) {{ __('databackd-analytics-builder.editor.avg') }}</label>
                                    </div>
                                    <button type="button" class="btn btn-link text-danger f-13 mb-2 ml-auto" id="rb_clear">
                                        <i class="fa fa-eraser mr-1"></i>{{ __('databackd-analytics-builder.editor.clear') }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Live preview --}}
                    <div class="dbk-card dbk-analytics-form-card">
                        <h4 class="dbk-analytics-form-head">
                            <i class="fa fa-eye text-lightest mr-2"></i>{{ __('databackd-analytics-builder.preview.title') }}
                        </h4>

                        <div class="dbk-analytics-form-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <x-forms.label fieldId="pv_period_type" :fieldLabel="__('databackd-analytics-builder.preview.period_type')" class="mt-3" />
                                    <select id="pv_period_type" class="form-control select-picker" data-size="8">
                                        @foreach (['month', 'quarter', 'year'] as $pt)
                                            <option value="{{ $pt }}">{{ __('databackd-analytics-builder.preview.periods.' . $pt) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label fieldId="pv_period" :fieldLabel="__('databackd-analytics-builder.preview.period')" class="mt-3" />
                                    <select id="pv_period" class="form-control select-picker" data-size="8"></select>
                                </div>
                                <div class="col-md-4">
                                    <x-forms.label fieldId="pv_entity" :fieldLabel="__('databackd-analytics-builder.preview.entity')" class="mt-3" />
                                    <select id="pv_entity" class="form-control select-picker" data-size="8">
                                        <option value="">{{ __('databackd-analytics-builder.preview.entity_all') }}</option>
                                        @foreach ($entities as $entity)
                                            <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex align-items-center flex-wrap mt-4 mb-3">
                                <div class="mr-4">
                                    <div class="f-12 text-lightest text-uppercase">{{ __('databackd-analytics-builder.preview.result') }}
                                        <span id="pv_period_label"></span></div>
                                    <div class="rb-result is-null" id="pv_result">—</div>
                                </div>
                                <div id="pv_status_badge" class="mr-auto"></div>
                                <div class="f-12 text-lightest" id="pv_code_line" style="display:none">
                                    {{ __('databackd-analytics-builder.preview.code') }}: <code id="pv_code"></code>
                                </div>
                            </div>

                            <div id="pv_message"></div>

                            <div id="pv_components" style="display:none">
                                <h6 class="f-13 text-dark-grey mt-3">{{ __('databackd-analytics-builder.preview.components') }}</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm rb-comp-table mb-0">
                                        <thead>
                                            <tr>
                                                <th>{{ __('databackd-analytics-builder.preview.col_variable') }}</th>
                                                <th class="text-right">{{ __('databackd-analytics-builder.preview.col_value') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody id="pv_components_body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Help --}}
            <div class="dbk-card dbk-analytics-form-card mb-3">
                <h4 class="dbk-analytics-form-head border-0 rb-help-toggle d-flex align-items-center" style="cursor:pointer"
                    data-toggle="collapse" data-target="#rb-help" aria-expanded="true">
                    <i class="fa fa-chevron-down text-lightest mr-3 f-14"></i>
                    <i class="fa fa-question-circle text-lightest mr-2"></i>{{ __('databackd-analytics-builder.help.title') }}
                </h4>
                <div class="collapse show border-top-grey" id="rb-help">
                    <div class="p-20">
                        <p class="f-14 text-dark-grey">{{ __('databackd-analytics-builder.help.intro') }}</p>
                        <div class="row">
                            <div class="col-lg-6 mb-3">
                                <h6 class="f-13 text-dark-grey">{{ __('databackd-analytics-builder.help.syntax_title') }}</h6>
                                <table class="table table-sm rb-syntax mb-0">
                                    <tbody>
                                        @foreach (__('databackd-analytics-builder.help.syntax') as $row)
                                            <tr>
                                                <td>{{ $row[0] }}</td>
                                                <td>{{ $row[1] }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-lg-6 mb-3">
                                <h6 class="f-13 text-dark-grey">{{ __('databackd-analytics-builder.help.examples_title') }}</h6>
                                @foreach (__('databackd-analytics-builder.help.examples') as $example)
                                    <div class="rb-example d-flex align-items-center">
                                        <div class="flex-grow-1 mr-2">
                                            <div class="f-13 text-darkest-grey">{{ $example[0] }}</div>
                                            <code>{{ $example[1] }}</code>
                                        </div>
                                        <button type="button" class="btn btn-secondary btn-sm f-12 rb-use-example"
                                            data-formula="{{ $example[1] }}">{{ __('databackd-analytics-builder.help.use_example') }}</button>
                                    </div>
                                @endforeach

                                <h6 class="f-13 text-dark-grey mt-3">{{ __('databackd-analytics-builder.help.notes_title') }}</h6>
                                <ul class="f-13 text-dark-grey pl-3 mb-0">
                                    @foreach (__('databackd-analytics-builder.help.notes') as $note)
                                        <li class="mb-1">{{ $note }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dbk-card dbk-analytics-form-card mb-3">
                <x-form-actions>
                    <x-forms.button-primary id="save-ratio-form" class="mr-3" icon="check">
                        {{ __('databackd-analytics-builder.form.save') }}
                    </x-forms.button-primary>
                    <x-forms.button-cancel :link="route('databackd.analytics.builder.index')" class="border-0">@lang('app.cancel')
                    </x-forms.button-cancel>
                    <span id="rb-save-hint" class="f-13 text-danger align-self-center ml-3" style="display:none">
                        <i class="fa fa-exclamation-circle mr-1"></i>{{ __('databackd-analytics-builder.form.save_blocked') }}
                    </span>
                </x-form-actions>
            </div>
        </x-form>
    </div>
@endsection

@push('scripts')
    @php
        $jsMessages = [
            'bad_mask' => __('databackd-analytics-builder.editor.bad_mask'),
            'pick_account' => __('databackd-analytics-builder.editor.pick_account'),
            'pick_value' => __('databackd-analytics-builder.editor.pick_value'),
            'avg_flow' => __('databackd-analytics-builder.editor.avg_flow'),
            'valid' => __('databackd-analytics-builder.preview.valid'),
            'invalid' => __('databackd-analytics-builder.preview.invalid'),
            'empty' => __('databackd-analytics-builder.preview.empty'),
            'null_value' => __('databackd-analytics-builder.preview.null_value'),
            'loading' => __('databackd-analytics-builder.preview.loading'),
            'failed' => __('databackd-analytics-builder.preview.request_failed'),
            'save_blocked' => __('databackd-analytics-builder.form.save_blocked'),
            'null_zero' => __('databackd-analytics-builder.preview.null_zero'),
            'keywords' => __('databackd-analytics-builder.autocomplete'),
        ];
        $acData = [
            'ACC' => $accounts->map(fn($a) => ['v' => (string)$a->code, 'l' => $a->name])->values(),
            'TYPE' => collect($types)->map(fn($x) => ['v' => $x, 'l' => __('databackd-analytics-common.account_types.' . $x)])->values(),
            'SUB' => collect($subtypes)->map(fn($x) => ['v' => $x, 'l' => __('databackd-analytics-common.account_subtypes.' . $x)])->values(),
            'REF' => $otherRatios->map(fn($r) => ['v' => $r->code, 'l' => $r->name])->values(),
        ];
    @endphp
    <script>
        $(function() {
            var isEdit = {{ $isEdit ? 'true' : 'false' }};
            var editCode = @json($isEdit ? $ratio->code : '');
            var csrf = "{{ csrf_token() }}";
            var previewUrl = "{{ route('databackd.analytics.builder.preview') }}";
            var saveUrl = "{{ $isEdit ? route('databackd.analytics.builder.update', $ratio->id) : route('databackd.analytics.builder.store') }}";
            var indexUrl = "{{ route('databackd.analytics.builder.index') }}";
            var periods = @json($periods);
            var statusColors = {good: 'success', warning: 'warning', bad: 'danger', none: 'secondary'};
            var t = @json($jsMessages);

            var $ta = $('#ratio_formula');
            var state = {valid: isEdit, pending: false};
            var timer = null, seq = 0, cursorSet = false;

            init('.content-wrapper');

            function esc(s) { return $('<div>').text(s === null || s === undefined ? '' : String(s)).html(); }

            function toast(message, icon) {
                Swal.fire({toast: true, position: 'top-end', icon: icon || 'warning', title: message,
                    showConfirmButton: false, timer: 3000, timerProgressBar: true});
            }

            // ---------------------------------------------------------------- text insertion at the cursor
            $ta.on('focus click keyup select', function() { cursorSet = true; });

            function insertText(text, spaceBefore, spaceAfter) {
                var el = $ta[0];
                el.focus();

                if (!cursorSet) {
                    el.setSelectionRange(el.value.length, el.value.length);
                    cursorSet = true;
                }

                var s = el.selectionStart, e = el.selectionEnd, v = el.value;
                var before = v.slice(0, s), after = v.slice(e);
                var pre = (spaceBefore && before.length && !/[\s(]$/.test(before)) ? ' ' : '';
                var post = (spaceAfter && after.length && !/^[\s)]/.test(after)) ? ' ' : '';
                var ins = pre + text + post;
                var done = false;

                // execCommand keeps the browser undo history of the textarea
                try { done = document.execCommand('insertText', false, ins); } catch (err) { done = false; }

                if (!done || el.value === v) {
                    el.value = before + ins + after;
                    var pos = (before + ins).length;
                    el.setSelectionRange(pos, pos);
                }

                $ta.trigger('input');
            }

            function replaceAll(text) {
                var el = $ta[0];
                el.focus();
                el.select();
                var done = false;

                try { done = document.execCommand('insertText', false, text); } catch (err) { done = false; }

                if (!done || el.value !== text) {
                    el.value = text;
                }

                cursorSet = true;
                $ta.trigger('input');
            }

            function variable(kind, arg, allowAvg) {
                var avg = $('#rb_avg').is(':checked');
                var text = kind + '[' + arg + ']';

                if (avg && allowAvg) {
                    text = 'AVG(' + text + ')';
                } else if (avg && kind === 'FLOW') {
                    toast(t.avg_flow);
                }

                $('#rb_avg').prop('checked', false);

                return text;
            }

            // Account (balance or turnover)
            $('#rb_mode').on('click', 'button', function() {
                $('#rb_mode button').removeClass('active');
                $(this).addClass('active');
            });

            $('#rb_add_account').on('click', function() {
                var mask = $.trim($('#rb_mask').val());
                var code = mask !== '' ? mask : $('#rb_account').val();

                if (!code) { toast(t.pick_account); return; }
                if (!/^[A-Za-z0-9._-]+\*?$/.test(code)) { toast(t.bad_mask); return; }

                var mode = $('#rb_mode .active').data('mode');
                insertText(variable(mode, code, mode === 'ACC'), true, true);
                $('#rb_mask').val('');
            });

            $('#rb_add_type').on('click', function() {
                var v = $('#rb_type').val();
                if (!v) { toast(t.pick_value); return; }
                insertText(variable('TYPE', v, true), true, true);
            });

            $('#rb_add_subtype').on('click', function() {
                var v = $('#rb_subtype').val();
                if (!v) { toast(t.pick_value); return; }
                insertText(variable('SUB', v, true), true, true);
            });

            $('#rb_add_ref').on('click', function() {
                var v = $('#rb_ref').val();
                if (!v) { toast(t.pick_value); return; }
                insertText(variable('REF', v, false), true, true);
            });

            // Operators, constants
            $('.btn-op').on('click', function() {
                var op = String($(this).data('op'));
                insertText(op, op !== ')', op !== '(');
            });

            $('.btn-const').on('click', function() {
                insertText(String($(this).data('const')), true, true);
            });

            // Functions: NAME() with the cursor between the brackets
            $('.btn-func').on('click', function() {
                insertText(String($(this).data('func')) + '()', true, true);
                var el = $ta[0], pos = el.value.slice(0, el.selectionStart).lastIndexOf(')');
                if (pos >= 0) { el.setSelectionRange(pos, pos); }
            });

            $('#rb_clear').on('click', function() { replaceAll(''); });

            // ---------------------------------------------------------------- autocomplete in the formula field
            // ACC[ / FLOW[ -> accounts, TYPE[ -> types, SUB[ -> subtypes, REF[ -> other ratios; a word -> variables, functions, constants
            var acData = @json($acData);
            var $ac = $('#rb_ac_list'), acItems = [], acIndex = 0, acRange = null;

            function acClose() {
                $ac.attr('hidden', true).empty();
                $ta.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
                acItems = [];
            }

            function acContext() {
                var el = $ta[0];
                if (el.selectionStart !== el.selectionEnd) return null;
                var before = el.value.slice(0, el.selectionStart);
                var m = /(ACC|FLOW|TYPE|SUB|REF)\s*\[([^\]\s]*)$/i.exec(before);

                if (m) {
                    var kind = m[1].toUpperCase(), part = m[2].toLowerCase();
                    var list = acData[kind === 'FLOW' ? 'ACC' : kind] || [];
                    var items = list.filter(function(o) {
                        // by the start of the code (501, 50, cash) or by a part of the name (каса, клиенти)
                        return part === '' || String(o.v).toLowerCase().indexOf(part) === 0
                            || String(o.l).toLowerCase().indexOf(part) !== -1;
                    }).slice(0, 50).map(function(o) {
                        var close = el.value.charAt(el.selectionStart) === ']' ? '' : ']';
                        return {code: o.v, label: o.l, insert: o.v + close};
                    });
                    return {from: el.selectionStart - m[2].length, items: items};
                }

                var w = /(^|[^A-Za-z_\[])([A-Za-z]+)$/.exec(before);

                if (w) {
                    var word = w[2].toUpperCase();
                    var kws = (t.keywords || []).filter(function(k) { return k[0].indexOf(word) === 0 && k[0] !== word; });
                    return {from: el.selectionStart - w[2].length, items: kws.map(function(k) { return {code: k[0], label: k[1], insert: k[0]}; })};
                }

                return null;
            }

            function acRender() {
                var ctx = acContext();
                if (!ctx || !ctx.items.length) { acClose(); return; }
                acItems = ctx.items; acRange = ctx.from; acIndex = 0;
                $ac.html($.map(acItems, function(o, i) {
                    return '<li role="option" id="rb_ac_' + i + '" data-i="' + i + '"><code>' + esc(o.code) + '</code><span>' + esc(o.label) + '</span></li>';
                }).join('')).removeAttr('hidden');
                $ta.attr('aria-expanded', 'true');
                acHighlight();
            }

            function acHighlight() {
                $ac.children().removeClass('is-active').attr('aria-selected', 'false');
                var $li = $ac.children().eq(acIndex).addClass('is-active').attr('aria-selected', 'true');
                $ta.attr('aria-activedescendant', 'rb_ac_' + acIndex);
                if ($li.length) {
                    var top = $li[0].offsetTop, h = $li[0].offsetHeight, list = $ac[0];
                    if (top < list.scrollTop) list.scrollTop = top;
                    else if (top + h > list.scrollTop + list.clientHeight) list.scrollTop = top + h - list.clientHeight;
                }
            }

            function acPick(i) {
                var item = acItems[i], el = $ta[0];
                if (!item) return;
                el.focus();
                el.setSelectionRange(acRange, el.selectionStart);
                var done = false;
                try { done = document.execCommand('insertText', false, item.insert); } catch (err) { done = false; }
                if (!done) {
                    var v = el.value, pos = acRange + item.insert.length;
                    el.value = v.slice(0, acRange) + item.insert + v.slice(el.selectionEnd);
                    el.setSelectionRange(pos, pos);
                }
                cursorSet = true;
                acClose();
                $ta.trigger('input');
                // "ACC[" and the like open the list of their values right away
                if (/[\[(]$/.test(item.insert)) { acRender(); }
            }

            $ta.on('input click', function() { acRender(); });
            $ta.on('keydown', function(e) {
                if (!acItems.length) return;
                if (e.key === 'ArrowDown') { acIndex = (acIndex + 1) % acItems.length; acHighlight(); e.preventDefault(); }
                else if (e.key === 'ArrowUp') { acIndex = (acIndex - 1 + acItems.length) % acItems.length; acHighlight(); e.preventDefault(); }
                else if (e.key === 'Enter' || e.key === 'Tab') { acPick(acIndex); e.preventDefault(); }
                else if (e.key === 'Escape') { acClose(); e.preventDefault(); }
            });
            $ta.on('blur', function() { setTimeout(acClose, 150); });
            $ac.on('mousedown', 'li', function(e) { e.preventDefault(); acPick(parseInt($(this).data('i'), 10)); });

            $('.rb-use-example').on('click', function() {
                replaceAll(String($(this).data('formula')));
                $('html, body').animate({scrollTop: $ta.offset().top - 120}, 200);
            });

            // ---------------------------------------------------------------- period selects
            function fillPeriods(type, selected) {
                var $p = $('#pv_period').empty();

                $.each(periods[type], function(i, o) {
                    $p.append($('<option>').val(o.value).text(o.label));
                });

                $p.val(selected || periods[type][0].value);
                $p.selectpicker('refresh');
            }

            fillPeriods('month', periods['default']);

            $('#pv_period_type').on('change', function() {
                fillPeriods($(this).val());
                schedule(0);
            });
            $('#pv_period, #pv_entity, #ratio_format, #ratio_higher_is_better').on('change', function() { schedule(0); });
            $('#ratio_target_min, #ratio_target_max').on('input', function() { schedule(400); });
            $ta.on('input', function() { schedule(400); });
            $('#ratio_name').on('input', function() { if (!isEdit) schedule(600); });

            // ---------------------------------------------------------------- live preview
            function schedule(delay) {
                clearTimeout(timer);
                state.pending = true;
                timer = setTimeout(runPreview, delay);
            }

            function setSaveState() {
                var blocked = !state.valid && !state.pending;
                $('#save-ratio-form').prop('disabled', blocked);
                $('#rb-save-hint').toggle(blocked && $.trim($ta.val()) !== '');
            }

            function runPreview() {
                var mySeq = ++seq;

                $('#pv_message').html('<div class="f-13 text-lightest"><i class="fa fa-spinner fa-spin mr-1"></i>' + esc(t.loading) + '</div>');

                $.ajax({
                    url: previewUrl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _token: csrf,
                        formula: $ta.val(),
                        period_type: $('#pv_period_type').val(),
                        period: $('#pv_period').val(),
                        entity_id: $('#pv_entity').val(),
                        format: $('#ratio_format').val(),
                        target_min: $('#ratio_target_min').val(),
                        target_max: $('#ratio_target_max').val(),
                        higher_is_better: $('#ratio_higher_is_better').is(':checked') ? 1 : 0,
                        code: editCode,
                        name: $('#ratio_name').val()
                    }
                }).done(function(r) {
                    if (mySeq !== seq) return;
                    state.pending = false;
                    render(r);
                }).fail(function() {
                    if (mySeq !== seq) return;
                    state.pending = false;
                    state.valid = false;
                    $('#pv_message').html('<div class="alert alert-warning f-13 mb-0">' + esc(t.failed) + '</div>');
                    setSaveState();
                });
            }

            function render(r) {
                var $result = $('#pv_result'), $msg = $('#pv_message');

                if (!isEdit && r.code) {
                    $('#ratio_code').val(r.code);
                }

                $('#pv_components').hide();
                $('#pv_status_badge').empty();
                $('#pv_period_label').text('');

                if (r.empty) {
                    state.valid = false;
                    $result.text('—').addClass('is-null');
                    $msg.html('<div class="alert alert-light border f-13 mb-0"><i class="fa fa-info-circle mr-1 text-lightest"></i>' + esc(t.empty) + '</div>');
                } else if (!r.valid) {
                    state.valid = false;
                    $result.text('—').addClass('is-null');
                    var items = $.map(r.errors, function(e) { return '<li>' + esc(e) + '</li>'; }).join('');
                    $msg.html('<div class="alert alert-danger f-13 mb-0"><strong><i class="fa fa-times-circle mr-1"></i>' + esc(t.invalid) +
                        '</strong><ul class="rb-errors mt-1">' + items + '</ul></div>');
                } else {
                    state.valid = true;
                    $result.text(r.formatted).toggleClass('is-null', r.value === null);
                    $('#pv_period_label').text(r.period_label ? '· ' + r.period_label : '');

                    if (r.status && r.status !== 'none') {
                        $('#pv_status_badge').html('<span class="badge badge-' + (statusColors[r.status] || 'secondary') + ' f-13 p-2">' + esc(r.status_label) + '</span>');
                    }

                    var html = '<div class="alert alert-success f-13 mb-0"><i class="fa fa-check-circle mr-1"></i>' + esc(t.valid);
                    if (r.value === null) {
                        // name the components that are 0 (the usual reason: no data in the period)
                        var zeros = $.grep(r.variables || [], function(v) { return v.value !== null && Math.abs(v.value) < 0.005; });
                        html += '<div class="text-dark-grey mt-1">' + esc(t.null_value) + '</div>';
                        if (zeros.length) {
                            html += '<div class="text-dark-grey mt-1">' + esc(t.null_zero.replace(':items', $.map(zeros, function(v) { return v.label; }).join('; '))) + '</div>';
                        }
                    }
                    $msg.html(html + '</div>');

                    if (r.variables && r.variables.length) {
                        var rows = $.map(r.variables, function(v) {
                            return '<tr><td>' + esc(v.label) + '<br><code>' + esc(v.key) + '</code></td>' +
                                '<td class="text-right text-nowrap">' + esc(v.formatted) + '</td></tr>';
                        }).join('');
                        $('#pv_components_body').html(rows);
                        $('#pv_components').show();
                    }
                }

                if (r.code) {
                    $('#pv_code').text(r.code);
                    $('#pv_code_line').show();
                } else {
                    $('#pv_code_line').hide();
                }

                setSaveState();
            }

            // ---------------------------------------------------------------- save
            $('#save-ratio-form').on('click', function() {
                if (!state.valid && !state.pending) {
                    toast(t.save_blocked);
                    return;
                }

                $.easyAjax({
                    url: saveUrl,
                    container: '#save-ratio-data-form',
                    type: 'POST',
                    disableButton: true,
                    blockUI: true,
                    buttonSelector: '#save-ratio-form',
                    data: $('#save-ratio-data-form').serialize(),
                    success: function(response) {
                        if (response.status === 'success') {
                            window.location.href = indexUrl;
                        }
                    }
                });
            });

            // First preview
            state.valid = false;
            schedule(0);
        });
    </script>
@endpush

<div class="row">
    <div class="col-sm-12">
        <x-form id="save-targets-data-form" method="PUT">

            <div class="add-client bg-white rounded">
                <h4 class="mb-0 p-20 f-21 font-weight-normal border-bottom-grey">
                    {{ $pageTitle }}
                </h4>

                <div class="row p-20">
                    <div class="col-md-12">
                        <h5 class="f-16 text-darkest-grey mb-1">{{ $ratio->name }}</h5>
                        <span class="badge badge-secondary mb-3">{{ __('databackd-analytics-common.ratio_groups.' . $ratio->group) }}</span>
                    </div>

                    <div class="col-md-12">
                        <label class="f-14 text-dark-grey mb-12">{{ __('databackd-analytics-builder.targets.formula') }}</label>
                        <pre class="f-13 bg-additional-grey rounded p-2 mb-1" style="white-space:pre-wrap;font-family:SFMono-Regular,Menlo,Consolas,monospace">{{ $ratio->formula }}</pre>
                        @if ($ratio->description)
                            <p class="f-13 text-lightest mb-0">{{ $ratio->description }}</p>
                        @endif
                    </div>

                    <div class="col-md-6">
                        <div class="form-group my-3">
                            <x-forms.label fieldId="target_min" :fieldLabel="__('databackd-analytics-builder.form.target_min')" />
                            <input type="number" step="any" class="form-control height-35 f-14" name="target_min"
                                id="target_min" value="{{ $ratio->target_min }}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group my-3">
                            <x-forms.label fieldId="target_max" :fieldLabel="__('databackd-analytics-builder.form.target_max')" />
                            <input type="number" step="any" class="form-control height-35 f-14" name="target_max"
                                id="target_max" value="{{ $ratio->target_max }}">
                        </div>
                    </div>
                    <div class="col-md-12">
                        <small class="form-text text-muted mt-0">{{ __('databackd-analytics-builder.form.target_help') }}</small>
                    </div>

                    <div class="col-md-12">
                        <x-forms.toggle-switch :fieldLabel="__('databackd-analytics-builder.form.higher_is_better')"
                            fieldName="higher_is_better" fieldId="targets_higher_is_better"
                            :checked="(bool)$ratio->higher_is_better"
                            :fieldHelp="__('databackd-analytics-builder.form.higher_is_better_help')" />
                    </div>
                </div>

                <x-form-actions>
                    <x-forms.button-primary id="save-targets-form" class="mr-3" icon="check">
                        {{ __('databackd-analytics-builder.targets.save') }}
                    </x-forms.button-primary>
                    <x-forms.button-cancel :link="route('databackd.analytics.builder.index')" class="border-0">@lang('app.cancel')
                    </x-forms.button-cancel>
                </x-form-actions>
            </div>
        </x-form>
    </div>
</div>

<script>
    $(document).ready(function() {
        $('#save-targets-form').click(function() {
            $.easyAjax({
                url: "{{ route('databackd.analytics.builder.targets.update', $ratio->id) }}",
                container: '#save-targets-data-form',
                type: "POST",
                disableButton: true,
                blockUI: true,
                buttonSelector: "#save-targets-form",
                data: $('#save-targets-data-form').serialize(),
                success: function(response) {
                    if (response.status === 'success') {
                        window.location.href = "{{ route('databackd.analytics.builder.index') }}";
                    }
                }
            });
        });

        init(RIGHT_MODAL);
    });
</script>

@php($L = 'databackd-analytics-consolidation-rules.')
@php($isEdit = $group->exists)
<x-form id="group-form" :method="$isEdit ? 'PUT' : 'POST'" class="ajax-form">
    <div class="modal-header">
        <h5 class="modal-title" id="modelHeading">
            @lang($isEdit ? $L . 'edit_group' : $L . 'add_group')
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('databackd-misc.close') }}"><span aria-hidden="true">×</span></button>
    </div>
    <div class="modal-body">
        <div class="portlet-body">
            <div class="row">
                <div class="col-lg-8">
                    <x-forms.text fieldId="group_name" fieldName="name" fieldRequired="true"
                                  :fieldLabel="__($L . 'form.name')"
                                  :fieldPlaceholder="__($L . 'form.name_placeholder')"
                                  :fieldValue="$group->name ?? ''"/>
                </div>
                <div class="col-lg-4">
                    <x-forms.text fieldId="group_base_currency" fieldName="base_currency" fieldRequired="true"
                                  :fieldLabel="__($L . 'form.base_currency')"
                                  :fieldValue="$group->base_currency ?: 'EUR'"/>
                </div>
                <div class="col-lg-12">
                    <small class="form-text text-muted mt-0 mb-2">@lang($L . 'form.base_currency_help')</small>
                </div>
                <div class="col-lg-12">
                    <x-forms.select fieldId="group_method" fieldName="method" fieldRequired="true"
                                    :fieldLabel="__($L . 'form.method')">
                        @foreach (\App\Models\Databackd\FinConsolidationGroup::METHODS as $method)
                            <option value="{{ $method }}" @selected($group->method === $method)>@lang('databackd-analytics-common.methods.' . $method)</option>
                        @endforeach
                    </x-forms.select>
                    <small class="form-text text-muted" id="method-help">@lang($L . 'form.method_help_' . ($group->method ?: 'full'))</small>
                </div>
                <div class="col-lg-6">
                    <x-forms.toggle-switch fieldId="group_eliminate_intercompany" fieldName="eliminate_intercompany"
                                           :fieldLabel="__($L . 'form.eliminate_intercompany')"
                                           :fieldHelp="__($L . 'form.eliminate_intercompany_help')"
                                           :checked="(bool)$group->eliminate_intercompany"/>
                </div>
                <div class="col-lg-6">
                    <x-forms.toggle-switch fieldId="group_minority_interest" fieldName="minority_interest"
                                           :fieldLabel="__($L . 'form.minority_interest')"
                                           :fieldHelp="__($L . 'form.minority_interest_help')"
                                           :checked="(bool)$group->minority_interest"/>
                </div>
                <div class="col-lg-12">
                    <x-forms.textarea fieldId="group_description" fieldName="description"
                                      :fieldLabel="__($L . 'form.description')"
                                      :fieldPlaceholder="__($L . 'form.description_placeholder')"
                                      :fieldValue="$group->description ?? ''"/>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <x-forms.button-cancel data-dismiss="modal" class="border-0 mr-3">@lang($L . 'form.close')</x-forms.button-cancel>
        <x-forms.button-primary id="save-group" icon="check">@lang($L . 'form.save')</x-forms.button-primary>
    </div>
</x-form>

<script>
    init(MODAL_LG);

    var methodHelp = {
        full: @json(__($L . 'form.method_help_full')),
        proportional: @json(__($L . 'form.method_help_proportional'))
    };

    $('#group_method').on('change', function () {
        $('#method-help').text(methodHelp[$(this).val()] || '');
    });

    $('#group_base_currency').on('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '').substr(0, 3);
    });

    // AFV (S12): validation on leaving the field (not while typing), the message under the field
    var groupFormChecks = {
        group_name: function (v) { return $.trim(v) === '' ? @json(__($L . 'validation.name_required')) : null; },
        group_base_currency: function (v) { return /^[A-Z]{3}$/.test($.trim(v)) ? null : @json(__($L . 'validation.currency_format')); }
    };

    function groupFieldValid($input) {
        var message = groupFormChecks[$input.attr('id')]($input.val());
        var $group = $input.closest('.form-group');

        $group.find('.invalid-feedback').remove();
        $input.toggleClass('is-invalid', !!message).attr('aria-invalid', message ? 'true' : null);

        if (message) {
            $group.append($('<div class="d-block invalid-feedback">').attr('id', $input.attr('id') + '-error').text(message));
            $input.attr('aria-describedby', $input.attr('id') + '-error');
        }

        return !message;
    }

    $('#group_name, #group_base_currency').on('blur', function () {
        groupFieldValid($(this));
    });

    $('#save-group').click(function () {
        var $invalid = $('#group_name, #group_base_currency').filter(function () {
            return !groupFieldValid($(this));
        });

        if ($invalid.length) {
            $invalid.first().trigger('focus');

            return false;
        }

        $.easyAjax({
            url: "{{ $isEdit ? route('databackd.analytics.consolidation-rules.update', $group->id) : route('databackd.analytics.consolidation-rules.store') }}",
            container: '#group-form',
            type: "POST",
            blockUI: true,
            disableButton: true,
            buttonSelector: "#save-group",
            data: $('#group-form').serialize(),
            success: function (response) {
                if (response.status == 'success') {
                    @if ($isEdit)
                        // a currency change clears the manual rates of the members - say so before the page reloads
                        if (response.fx_cleared) {
                            Swal.fire({icon: 'warning', text: response.message, confirmButtonText: @json(__('app.ok')),
                                customClass: {confirmButton: 'btn btn-primary'}, buttonsStyling: false})
                                .then(function () { window.location.reload(); });

                            return;
                        }

                        window.location.reload();
                    @else
                        window.location.href = response.group_url;
                    @endif
                }
            }
        });
    });
</script>

@extends('layouts.app')

@section('content')
    @php
        $L = 'databackd-analytics-consolidation-rules.';
        $fmt = fn($n) => number_format($n, 0, ',', ' ');
    @endphp

    <div class="content-wrapper dbk-analytics">
        <x-dbk.page-header :subtitle="__($L . 'subtitle')">
            <x-slot name="actions">
                <div id="table-actions">
                    <x-forms.button-primary class="add-group" icon="plus">@lang($L . 'add_group')</x-forms.button-primary>
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__($L . 'summary.groups')" :value="$fmt($groups->count())" icon="bi-collection" />
            <x-dbk.kpi :label="__($L . 'summary.entities')" :value="$fmt($membersTotal)" icon="bi-building" />
            <x-dbk.kpi :label="__($L . 'summary.rules')" :value="$fmt($rulesTotal)" icon="bi-shuffle" />
            <x-dbk.kpi :label="__($L . 'summary.entities_total')" :value="$fmt($entitiesTotal)" icon="bi-diagram-3" />
        </div>

        <div class="dbk-card">
            @if ($groups->isEmpty())
                <x-dbk.empty icon="collection" :title="__($L . 'groups_empty')" :text="__($L . 'groups_empty_hint')">
                    <x-forms.button-primary class="add-group" icon="plus">@lang($L . 'add_group')</x-forms.button-primary>
                </x-dbk.empty>
            @else
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table dbk-cg-table" id="groups-table">
                        <thead>
                            <tr>
                            <th>@lang($L . 'columns.name')</th>
                            <th>@lang($L . 'columns.method')</th>
                            <th>@lang($L . 'columns.currency')</th>
                            <th>@lang($L . 'columns.entities')</th>
                            <th class="text-center">@lang($L . 'columns.rules')</th>
                            <th>@lang($L . 'columns.options')</th>
                            <th class="num">@lang($L . 'columns.actions')</th>
                            </tr>
                        </thead>
                        <tbody>

                        @foreach ($groups as $group)
                            <tr id="group-row-{{ $group->id }}">
                                <td>
                                    <a class="dbk-analytics-ratio-name" href="{{ route('databackd.analytics.consolidation-rules.show', $group->id) }}">{{ $group->name }}</a>
                                    @if ($group->description)
                                        <div class="dbk-analytics-hint text-truncate dbk-cg-desc" title="{{ $group->description }}">{{ $group->description }}</div>
                                    @endif
                                </td>
                                <td>@lang('databackd-analytics-common.methods.' . $group->method)</td>
                                <td><span class="dbk-chip">{{ $group->base_currency }}</span></td>
                                <td>@lang($L . 'entities_count', ['included' => $group->included_count, 'total' => $group->group_entities_count])</td>
                                <td class="text-center">{{ $group->rules_count }}</td>
                                <td>
                                    @if ($group->eliminate_intercompany)
                                        <span class="dbk-badge is-good mr-1" title="@lang($L . 'flags.eliminate_intercompany')">@lang($L . 'flags.eliminate_intercompany_short')</span>
                                    @endif
                                    @if ($group->minority_interest)
                                        <span class="dbk-badge is-info" title="@lang($L . 'flags.minority_interest')">@lang($L . 'flags.minority_interest_short')</span>
                                    @endif
                                    @if (!$group->eliminate_intercompany && !$group->minority_interest)
                                        <span class="dbk-text-muted">@lang($L . 'flags.off')</span>
                                    @endif
                                </td>
                                <td class="num dbk-analytics-actions dbk-cg-actions">
                                    {{-- AFV (S12): accessible names for the icon buttons --}}
                                    <a href="{{ route('databackd.analytics.consolidation-rules.show', $group->id) }}"
                                       class="btn btn-sm btn-outline-primary" title="@lang($L . 'actions.settings')" aria-label="@lang($L . 'actions.settings')">
                                        <i class="fa fa-cog"></i>
                                    </a>
                                    @if ($consolidationRoute)
                                        <a href="{{ route($consolidationRoute, ['group' => $group->id]) }}"
                                           class="btn btn-sm btn-outline-secondary ml-1" title="@lang($L . 'actions.open_consolidation')" aria-label="@lang($L . 'actions.open_consolidation')">
                                            <i class="fa fa-table"></i>
                                        </a>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-group ml-1"
                                            data-id="{{ $group->id }}" title="@lang($L . 'actions.edit')" aria-label="@lang($L . 'actions.edit')">
                                        <i class="fa fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-group ml-1"
                                            data-id="{{ $group->id }}" data-name="{{ $group->name }}"
                                            title="@lang($L . 'actions.delete')" aria-label="@lang($L . 'actions.delete')">
                                        <i class="fa fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $('body').on('click', '.add-group', function () {
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, "{{ route('databackd.analytics.consolidation-rules.create') }}");
        });

        // AFV (S12): "Create a group" from the empty consolidation worksheet opens the form directly
        @if (request()->boolean('create'))
            $(function () {
                $('.add-group').first().trigger('click');

                if (window.history && history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.delete('create');
                    history.replaceState(null, '', url.toString());
                }
            });
        @endif

        $('body').on('click', '.edit-group', function () {
            var url = "{{ route('databackd.analytics.consolidation-rules.edit', ':id') }}".replace(':id', $(this).data('id'));
            $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
            $.ajaxModal(MODAL_LG, url);
        });

        $('body').on('click', '.delete-group', function () {
            var id = $(this).data('id');
            var name = $(this).data('name');

            Swal.fire({
                title: @json(__($L . 'confirm_title')),
                text: @json(__($L . 'delete_group_confirm', ['name' => ':name'])).replace(':name', name),
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
                    url: "{{ route('databackd.analytics.consolidation-rules.destroy', ':id') }}".replace(':id', id),
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
    </script>
@endpush

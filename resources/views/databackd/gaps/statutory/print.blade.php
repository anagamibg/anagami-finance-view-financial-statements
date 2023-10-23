<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@lang('databackd-gaps-statutory.export.pdf_title')</title>
    @include('databackd.gaps.statutory._css', ['mode' => 'print'])
</head>
<body class="st-doc">
<div class="st-toolbar">
    <button type="button" onclick="window.print()">@lang('databackd-gaps-statutory.actions.print')</button>
    <button type="button" class="secondary" onclick="window.close()">@lang('databackd-gaps-statutory.export.print_close')</button>
</div>

@foreach (['balance' => $balance, 'income' => $income] as $kind => $statement)
    <div class="st-page">
        @include('databackd.gaps.statutory._header', ['info' => $info, 'statement' => $statement, 'logo' => null])
        @include('databackd.gaps.statutory._statement', ['statement' => $statement, 'showAccounts' => false])
        <div class="st-foot">{{ $info['name'] }} · @lang('databackd-gaps-statutory.document.generated_at', ['date' => $generatedAt])</div>
    </div>
@endforeach

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>

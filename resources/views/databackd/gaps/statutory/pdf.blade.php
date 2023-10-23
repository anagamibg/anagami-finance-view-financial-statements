<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>@lang('databackd-gaps-statutory.export.pdf_title')</title>
    @include('databackd.gaps.statutory._css', ['mode' => 'pdf'])
</head>
<body class="st-doc">
@foreach (['balance' => $balance, 'income' => $income] as $kind => $statement)
    <div class="st-page {{ $kind === 'income' ? 'st-last' : '' }}">
        @include('databackd.gaps.statutory._header', ['info' => $info, 'statement' => $statement, 'logo' => $logo])
        @include('databackd.gaps.statutory._statement', ['statement' => $statement, 'showAccounts' => false])
    </div>
@endforeach
<div class="st-foot">{{ $info['name'] }} · @lang('databackd-gaps-statutory.document.generated_at', ['date' => $generatedAt]) · @lang('databackd-gaps-statutory.document.disclaimer')</div>
</body>
</html>

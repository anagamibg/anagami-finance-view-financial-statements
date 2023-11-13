<?php

namespace App\Exports\Databackd\Gaps\Statutory;

use App\Models\Company;
use App\Services\Databackd\Gaps\Statutory\StatutoryReport;

/**
 * Data of the annual statements document shared by the print page, the PDF and the Excel export, so that all of them
 * show exactly the same figures as the screen. No dependency on the session or a logged in user: the company is
 * passed explicitly.
 */
class StatutoryDocument
{

    /**
     * @return array{company: ?Company, year: int, previousYear: int, scope: array, info: array, balance: array,
     *               income: array, warnings: array, generatedAt: string}
     */
    public static function prepare(int $year, array $scope, int $companyId): array
    {
        $report = new StatutoryReport($companyId);

        return [
            'company' => Company::find($companyId),
            'year' => $year,
            'previousYear' => $year - 1,
            'scope' => $scope,
            'info' => $report->scopeInfo($scope),
            'balance' => $report->balanceSheet($year, $scope),
            'income' => $report->incomeStatement($year, $scope),
            'warnings' => $report->warnings($year, $scope),
            'generatedAt' => now()->format('d.m.Y H:i'),
        ];
    }

    /** Amount for the tables: thousands separated by a non-breaking space, zero as a dash. */
    public static function money(?float $value): string
    {
        if ($value === null) {
            return '';
        }

        if (abs($value) < 0.005) {
            return '–';
        }

        return ($value < 0 ? '-' : '') . number_format(abs($value), 2, ',', "\u{00A0}");
    }

    /** Document title lines of a statement: [title, comparative line]. */
    public static function titles(array $statement): array
    {
        $current = \Carbon\Carbon::parse($statement['dates']['current'])->format('d.m.Y');
        $previous = \Carbon\Carbon::parse($statement['dates']['previous'])->format('d.m.Y');

        if ($statement['kind'] === 'balance') {
            return [
                __('databackd-gaps-statutory.document.balance_title', ['date' => $current]),
                __('databackd-gaps-statutory.document.comparative', ['date' => $previous]),
            ];
        }

        return [
            __('databackd-gaps-statutory.document.income_title', ['date' => $current]),
            __('databackd-gaps-statutory.document.comparative', ['date' => $previous]),
        ];
    }

    /** Lines under the company name: EIK or the consolidation description. */
    public static function infoLines(array $info): array
    {
        $lines = [];

        if ($info['eik']) {
            $lines[] = __('databackd-gaps-statutory.document.eik', ['eik' => $info['eik']]);
        }

        if ($info['consolidated']) {
            $lines[] = __('databackd-gaps-statutory.document.consolidated');

            if ($info['method']) {
                $lines[] = __('databackd-gaps-statutory.document.method', ['method' => __('databackd-gaps-statutory.document.methods.' . $info['method'])]);
            }
        }

        if ($info['consolidated'] && $info['entities']) {
            $lines[] = __('databackd-gaps-statutory.document.entities', ['list' => implode(', ', $info['entities'])]);
        }

        return $lines;
    }

    /** File name (ASCII) for the downloads: <prefix>-<year>-<scope>.<ext>. */
    public static function fileName(int $year, array $scope, string $extension): string
    {
        $scopePart = !empty($scope['group_id']) ? 'group-' . $scope['group_id'] : (!empty($scope['entity_id']) ? 'entity-' . $scope['entity_id'] : 'company');

        return __('databackd-gaps-statutory.export.file') . '-' . $year . '-' . $scopePart . '.' . $extension;
    }

}

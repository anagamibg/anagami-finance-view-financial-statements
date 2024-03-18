<?php

namespace App\Exports\Databackd\Gaps\Margins;

use App\Services\Databackd\Gaps\Margins\MarginReport;
use App\Services\Databackd\Reports\ReportFilters;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel export of the margins report: sheet 1 = products and services, sheet 2 = product groups.
 * Numbers are real numbers with a number format, the header row is frozen. Works without a session (company id is passed).
 */
class MarginExport implements WithMultipleSheets
{

    protected array $report;

    public function __construct(protected ReportFilters $filters, protected int $companyId, protected ?int $categoryId = null)
    {
        $this->report = (new MarginReport($companyId))->build($filters, $categoryId);
    }

    public function sheets(): array
    {
        return [
            new MarginProductsSheet($this->report, $this->filters, $this->companyId),
            new MarginGroupsSheet($this->report, $this->filters, $this->companyId),
        ];
    }

}

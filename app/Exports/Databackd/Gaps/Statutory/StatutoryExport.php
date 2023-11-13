<?php

namespace App\Exports\Databackd\Gaps\Statutory;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Excel export of the annual statements: one sheet with the balance sheet and one with the income statement, real
 * numbers (not text), comparative column, mapped accounts and the notes.
 */
class StatutoryExport implements WithMultipleSheets
{

    protected array $document;

    public function __construct(protected int $year, protected array $scope, protected int $companyId)
    {
        $this->document = StatutoryDocument::prepare($year, $scope, $companyId);
    }

    public function sheets(): array
    {
        return [
            new StatutorySheet($this->document, 'balance'),
            new StatutorySheet($this->document, 'income'),
        ];
    }

}

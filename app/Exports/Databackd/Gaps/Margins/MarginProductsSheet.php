<?php

namespace App\Exports\Databackd\Gaps\Margins;

/**
 * Sheet "Products": one row per product / service. Rows without a cost price have empty cost and margin cells.
 */
class MarginProductsSheet extends MarginSheet
{

    protected function sheetTitle(): string
    {
        return __('databackd-gaps-margins.export.sheet_products');
    }

    protected function hasCompare(): bool
    {
        return $this->report['compare'] !== null;
    }

    protected function headers(): array
    {
        $c = 'databackd-gaps-margins.table.columns.';
        $headers = [
            __($c . 'product'), __($c . 'sku'), __($c . 'group'), __($c . 'quantity'), __($c . 'avg_price'), __($c . 'cost_price'),
            __($c . 'credited'), __($c . 'revenue'), __($c . 'cost'), __($c . 'margin'), __($c . 'margin_pct'), __($c . 'share'),
        ];

        if ($this->hasCompare()) {
            $headers[] = __($c . 'prev_revenue');
            $headers[] = __($c . 'prev_margin');
            $headers[] = __($c . 'change');
        }

        return $headers;
    }

    protected function body(): void
    {
        foreach ($this->report['rows'] as $r) {
            // AFV (S10): name / SKU / group are user-controlled text - neutralized against formula injection
            $line = [
                self::neutralizeFormula($r['name']), self::neutralizeFormula($r['sku']), self::neutralizeFormula($r['group']), (float)$r['quantity'], $r['avg_price'], $r['cost_price'],
                (float)$r['credited'], (float)$r['revenue'], $r['cost'], $r['margin'], $this->ratio($r['margin_pct']), $this->ratio($r['share']),
            ];

            if ($this->hasCompare()) {
                $line[] = (float)($r['prev_revenue'] ?? 0);
                $line[] = $r['prev_margin'] ?? null;
                $line[] = $this->ratio($r['margin_change_pct'] ?? null);
            }

            $this->lines[] = $line;
        }

        $t = $this->report['totals'];
        $total = [
            __('databackd-gaps-margins.table.total'), null, null, (float)$t['quantity'], null, null,
            (float)$t['credited'], (float)$t['revenue'], $t['costed_count'] ? $t['cost'] : null, $t['margin'], $this->ratio($t['margin_pct']), 1.0,
        ];

        if ($this->hasCompare()) {
            $total[] = (float)$this->report['compare']['totals']['revenue'];
            $total[] = $this->report['compare']['totals']['margin'];
            $total[] = $this->ratio($this->report['compare']['changes']['margin_pct'] ?? null);
        }

        $this->lines[] = $total;
        $this->totalRow = count($this->lines);

        // D quantity, E-J money, K-L percent, then the comparison columns
        $this->formats = [
            'D' => self::QTY_FORMAT, 'E' => self::NUMBER_FORMAT, 'F' => self::NUMBER_FORMAT, 'G' => self::NUMBER_FORMAT,
            'H' => self::NUMBER_FORMAT, 'I' => self::NUMBER_FORMAT, 'J' => self::NUMBER_FORMAT,
            'K' => self::PCT_FORMAT, 'L' => self::PCT_FORMAT,
        ];

        if ($this->hasCompare()) {
            $this->formats['M'] = self::NUMBER_FORMAT;
            $this->formats['N'] = self::NUMBER_FORMAT;
            $this->formats['O'] = self::PCT_FORMAT;
        }
    }

}

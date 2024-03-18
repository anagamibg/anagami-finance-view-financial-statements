<?php

namespace App\Exports\Databackd\Gaps\Margins;

/**
 * Sheet "Product groups": revenue, cost, margin and share per product group.
 */
class MarginGroupsSheet extends MarginSheet
{

    protected function sheetTitle(): string
    {
        return __('databackd-gaps-margins.export.sheet_groups');
    }

    protected function hasCompare(): bool
    {
        return $this->report['compare'] !== null;
    }

    protected function headers(): array
    {
        $c = 'databackd-gaps-margins.table.columns.';
        $headers = [__($c . 'group'), __('databackd-gaps-margins.groups.products'), __($c . 'revenue'), __($c . 'cost'), __($c . 'margin'), __($c . 'margin_pct'), __($c . 'share')];

        if ($this->hasCompare()) {
            $headers[] = __($c . 'prev_margin');
            $headers[] = __($c . 'change');
        }

        return $headers;
    }

    protected function body(): void
    {
        foreach ($this->report['groups'] as $g) {
            // AFV (S10): the group name is user-controlled text - neutralized against formula injection
            $line = [self::neutralizeFormula($g['name']), (int)$g['products'], (float)$g['revenue'], $g['cost'], $g['margin'], $this->ratio($g['margin_pct']), $this->ratio($g['share'])];

            if ($this->hasCompare()) {
                $line[] = $g['prev_margin'] ?? null;
                $line[] = $this->ratio($g['margin_change_pct'] ?? null);
            }

            $this->lines[] = $line;
        }

        $t = $this->report['totals'];
        $total = [__('databackd-gaps-margins.table.total'), (int)$t['products'], (float)$t['revenue'], $t['costed_count'] ? $t['cost'] : null, $t['margin'], $this->ratio($t['margin_pct']), 1.0];

        if ($this->hasCompare()) {
            $total[] = $this->report['compare']['totals']['margin'];
            $total[] = $this->ratio($this->report['compare']['changes']['margin_pct'] ?? null);
        }

        $this->lines[] = $total;
        $this->totalRow = count($this->lines);

        $this->formats = ['C' => self::NUMBER_FORMAT, 'D' => self::NUMBER_FORMAT, 'E' => self::NUMBER_FORMAT, 'F' => self::PCT_FORMAT, 'G' => self::PCT_FORMAT];

        if ($this->hasCompare()) {
            $this->formats['H'] = self::NUMBER_FORMAT;
            $this->formats['I'] = self::PCT_FORMAT;
        }
    }

}

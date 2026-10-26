<?php

namespace App\Support\QuickBooks;

/**
 * Reads QuickBooks' Profit and Loss report (docs/slice-28-quickbooks-plan.md,
 * step 28b; decision 189, question 5): the totals QuickBooks itself gives
 * for income, cost of goods sold, expenses, net operating income and net
 * income. Backoffice takes QuickBooks' totals as they are; it adds nothing
 * up again (product-architecture.md, 9.8).
 */
final class ProfitAndLossReport
{
    /** QuickBooks' name for each total Backoffice keeps. */
    private const array GROUPS = [
        'income' => 'Income',
        'costOfGoods' => 'COGS',
        'expenses' => 'Expenses',
        'netOperatingIncome' => 'NetOperatingIncome',
        'netIncome' => 'NetIncome',
    ];

    /**
     * @param  array<string, mixed>  $report
     * @return array{income: int, costOfGoods: int, expenses: int, netOperatingIncome: int, netIncome: int}
     *
     * @throws QuickBooksUnavailable
     */
    public static function totals(array $report): array
    {
        $rows = $report['Rows']['Row'] ?? null;
        $found = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $group = is_array($row) ? ($row['group'] ?? null) : null;
            $columns = is_array($row) ? ($row['Summary']['ColData'] ?? null) : null;

            if (is_string($group) && is_array($columns) && $columns !== []) {
                $found[$group] = self::cents(end($columns)['value'] ?? null);
            }
        }

        if (! array_key_exists('NetIncome', $found)) {
            throw new QuickBooksUnavailable(__('QuickBooks\' profit and loss could not be read.'));
        }

        return array_map(fn (string $group): int => $found[$group] ?? 0, self::GROUPS);
    }

    private static function cents(mixed $value): int
    {
        return is_numeric($value) ? (int) round((float) $value * 100) : 0;
    }
}

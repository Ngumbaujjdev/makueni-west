<?php

namespace App\Exports;

use App\Models\Budget;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Collection;

class BudgetsExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle
{
    protected $query;

    public function __construct($query)
    {
        $this->query = $query;
    }

    /**
     * Return the collection of budgets to export
     */
    public function collection()
    {
        return $this->query->get();
    }

    /**
     * Define the column headings
     */
    public function headings(): array
    {
        return [
            'Budget Name',
            'Fiscal Year',
            'Budget Type',
            'Status',
            'Income Budgeted',
            'Expense Budgeted',
            'Net Budget',
            'Income Actual',
            'Expense Actual',
            'Net Actual',
            'Variance',
            'Created By',
            'Approved By',
            'Date Created',
            'Date Approved',
            'Description',
        ];
    }

    /**
     * Map each row to the desired format
     */
    public function map($budget): array
    {
        $incomeBudgeted = floatval($budget->total_income_budgeted ?? 0);
        $expenseBudgeted = floatval($budget->total_expense_budgeted ?? 0);
        $netBudget = $incomeBudgeted - $expenseBudgeted;

        $incomeActual = floatval($budget->total_income_actual ?? 0);
        $expenseActual = floatval($budget->total_expense_actual ?? 0);
        $netActual = $incomeActual - $expenseActual;

        $variance = $netActual - $netBudget;

        return [
            $budget->name,
            $budget->fiscal_year,
            $budget->budgetType->name ?? 'N/A',
            $budget->status->name ?? 'N/A',
            number_format($incomeBudgeted, 2),
            number_format($expenseBudgeted, 2),
            number_format($netBudget, 2),
            number_format($incomeActual, 2),
            number_format($expenseActual, 2),
            number_format($netActual, 2),
            number_format($variance, 2),
            $budget->creator ? $budget->creator->full_name : 'N/A',
            $budget->approver ? $budget->approver->full_name : 'N/A',
            $budget->created_at->format('Y-m-d H:i:s'),
            $budget->approved_at ? $budget->approved_at->format('Y-m-d H:i:s') : 'N/A',
            $budget->description ?? '',
        ];
    }

    /**
     * Apply styles to the worksheet
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'size' => 12,
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'font' => [
                    'color' => ['rgb' => 'FFFFFF'],
                    'bold' => true,
                ],
            ],
        ];
    }

    /**
     * Sheet title
     */
    public function title(): string
    {
        return 'Budgets';
    }
}

<?php

namespace App\Exports;

use App\Models\WorkingHours;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class workHoursExport implements FromCollection, WithHeadings
{
    /**
     * @return \Illuminate\Support\Collection
     */
    protected $request;
    public function __construct($request)
    {
        $this->request = $request;
    }
    public function collection()
    {
        $userId = auth()->user()->id;
        $filterMonth = Carbon::parse($this->request->month);
        $workHours = WorkingHours::where('user_id', $userId)
            ->whereYear('start_date_time', $filterMonth->year)
            ->whereMonth('start_date_time', $filterMonth->month)
            ->get(['start_date_time', 'end_date_time', 'total_hours', 'summary']);

        return new Collection($workHours);
    }

    public function headings(): array
    {
        return [
            'Start Date Time',
            'End Date Time',
            'Total Working Hours',
            'Summary'
        ];
    }
}

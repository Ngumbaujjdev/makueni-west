<?php

namespace App\Reports\Facilities;

use App\Models\Equipment;
use App\Models\Room;
use App\Reports\ReportColumn;
use App\Reports\ReportContext;
use App\Reports\ReportData;
use App\Reports\ReportSection;

/**
 * Room by room - a stock-take sheet (P5 round 3): what should be in each
 * room, with an empty "Checked" column to tick on paper, and what is out on
 * loan or being repaired so a missing thing isn't counted as lost.
 */
final class RoomByRoomReport extends FacilitiesReport
{
    public function key(): string
    {
        return 'facilities.rooms';
    }

    public function title(): string
    {
        return 'Room by room (stock-take)';
    }

    public function description(): string
    {
        return 'What should be in each room - asset number, how many, condition - with a column to tick when you check it. Things out on loan or being repaired are marked.';
    }

    public function icon(): string
    {
        return 'ri-door-open-line';
    }

    public function subject(): string
    {
        return 'stock-take';
    }

    public function inputs(): array
    {
        return [];
    }

    public function build(ReportContext $context): ReportData
    {
        $f = $this->facilities();
        $church = $context->territory;
        $items = Equipment::with('room')->withCount(['repairs as open_repairs' => fn ($q) => $q->where('status', '!=', 'done')])
            ->where('territory_id', $church->id)->orderBy('name')->get();
        $out = $f->onLoan($items->pluck('id')->all());
        $rooms = Room::where('territory_id', $church->id)->orderBy('order')->orderBy('name')->get();
        $row = fn (Equipment $e) => [
            $e->asset_no ?: '-', $e->name, $f->kind($church, $e->category)[0], (int) $e->quantity, Equipment::CONDITIONS[$e->condition][0] ?? '-',
            collect([($out[$e->id] ?? 0) ? ((int) $out[$e->id]).' on loan' : null, $e->open_repairs ? 'Being repaired' : null])->filter()->implode(', ') ?: '-', '',
        ];
        $columns = fn () => [
            ReportColumn::text('Asset no'), ReportColumn::text('Item', true), ReportColumn::text('Kind'), ReportColumn::number('How many', 'sum'),
            ReportColumn::text('Condition'), ReportColumn::text('Away'), ReportColumn::text('Checked'),
        ];
        $sections = [];
        foreach ($rooms as $r) {
            $list = $items->where('room_id', $r->id);
            if ($list->isNotEmpty()) {
                $sections[] = new ReportSection($r->name, $columns(), $list->map($row)->values()->all(), null, "In {$r->name}");
            }
        }
        $none = $items->filter(fn ($e) => ! $e->room_id || ! $rooms->contains('id', $e->room_id));
        if ($none->isNotEmpty()) {
            $sections[] = new ReportSection('Not kept in a room', $columns(), $none->map($row)->values()->all(), null, 'Not in a room');
        }
        $today = $f->today();

        return new ReportData(
            kicker: $context->kicker($this->subject()),
            title: 'Room by room',
            periodLabel: 'As at '.$today->format('j M Y'),
            scopeLabel: $context->scopeLabel(),
            tiles: [
                ['label' => 'Rooms', 'value' => (string) $rooms->count(), 'tone' => 'primary'],
                ['label' => 'Items', 'value' => number_format((int) $items->sum('quantity')), 'tone' => 'purple'],
                ['label' => 'Out on loan', 'value' => (string) (int) collect($out)->sum(), 'tone' => 'warning'],
                ['label' => 'Being repaired', 'value' => (string) $items->where('open_repairs', '>', 0)->count(), 'tone' => 'danger'],
            ],
            meta: ['As at' => $today->format('j M Y'), 'Prepared by' => $context->preparedBy(), 'Checked by' => '______________________', 'How to use it' => 'Walk each room, count what is there and tick "Checked". Anything missing that is not on loan or being repaired: report it.'],
            sections: $sections ?: [new ReportSection('Rooms', [ReportColumn::text('Item')], [], 'Nothing recorded yet.')],
            insights: [],
        );
    }
}

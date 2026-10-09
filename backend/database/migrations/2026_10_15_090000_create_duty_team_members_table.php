<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P5 round 4 (docs/specs/people-and-care-spec.md): the people on each duty's
 * team get their own table, for the Teams page. Until now they were a list
 * inside territories.metadata.facilities_setup.duties[].team - moved here in
 * their order, and back on the way down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('territory_id')->constrained('territories')->cascadeOnDelete();
            $table->string('duty', 40);
            $table->foreignId('person_id')->nullable()->constrained('people')->cascadeOnDelete();
            $table->string('name', 120)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['territory_id', 'duty', 'person_id']);
            $table->index(['territory_id', 'duty', 'position']);
        });

        foreach ($this->churches() as $t) {
            $m = json_decode($t->metadata, true) ?: [];
            $live = DB::table('people')->where('territory_id', $t->id)->pluck('id')->flip();
            foreach ($m['facilities_setup']['duties'] ?? [] as $i => $d) {
                $seen = [];
                foreach (array_values($d['team'] ?? []) as $pos => $p) {
                    $pid = ! empty($p['person_id']) && isset($live[$p['person_id']]) ? (int) $p['person_id'] : null;
                    $name = $pid ? null : (trim((string) ($p['name'] ?? '')) ?: null);
                    $key = $pid ? "p{$pid}" : 'n'.mb_strtolower((string) $name);
                    if ((! $pid && ! $name) || isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    DB::table('duty_team_members')->insert(['territory_id' => $t->id, 'duty' => $d['key'], 'person_id' => $pid, 'name' => $name, 'position' => $pos, 'created_at' => now(), 'updated_at' => now()]);
                }
                unset($m['facilities_setup']['duties'][$i]['team']);
            }
            DB::table('territories')->where('id', $t->id)->update(['metadata' => json_encode($m)]);
        }
    }

    public function down(): void
    {
        foreach ($this->churches() as $t) {
            $m = json_decode($t->metadata, true) ?: [];
            foreach ($m['facilities_setup']['duties'] ?? [] as $i => $d) {
                $m['facilities_setup']['duties'][$i]['team'] = DB::table('duty_team_members as m')->leftJoin('people as p', 'p.id', '=', 'm.person_id')
                    ->where('m.territory_id', $t->id)->where('m.duty', $d['key'])->orderBy('m.position')->orderBy('m.id')
                    ->get(['m.person_id', 'm.name', 'p.first_name', 'p.last_name'])
                    ->map(fn ($r) => ['person_id' => $r->person_id, 'name' => $r->person_id ? trim("{$r->first_name} {$r->last_name}") : $r->name])->all();
            }
            DB::table('territories')->where('id', $t->id)->update(['metadata' => json_encode($m)]);
        }
        Schema::dropIfExists('duty_team_members');
    }

    private function churches()
    {
        return DB::table('territories')->whereNotNull('metadata')->where('metadata', 'like', '%facilities_setup%')->get(['id', 'metadata']);
    }
};

<?php

namespace App\Approval\Contracts;

use App\Models\ApprovalRequest;
use Illuminate\Support\Collection;

/** Turns one step's settings into the people who approve it, for this request. */
interface ApproverResolver
{
    /** @return Collection<int, \App\Models\User> */
    public function resolve(array $config, ApprovalRequest $request): Collection;

    /** A plain description for the rule editor: "Senior Pastor of the church". */
    public function describe(array $config): string;
}

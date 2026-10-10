<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Unit::query()->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        return response()->json(['data' => Unit::create($this->validated($request))], 201);
    }

    public function update(Request $request, Unit $unit)
    {
        $unit->update($this->validated($request, $unit));

        return response()->json(['data' => $unit]);
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'], 'company' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('units')->ignore($unit?->id)],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index()
    {
        return view('units.index', ['units' => Unit::withCount('allocations')->orderBy('company')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        Unit::create($this->validated($request));

        return back()->with('success', 'Unidade cadastrada. Ela já está disponível para novos rateios.');
    }

    public function update(Request $request, Unit $unit)
    {
        $unit->update($this->validated($request, $unit));

        return back()->with('success', 'Dados da unidade atualizados.');
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('units', 'slug')->ignore($unit?->id)],
        ], ['slug.regex' => 'Use letras minúsculas, números e hífens no identificador.']);
    }
}

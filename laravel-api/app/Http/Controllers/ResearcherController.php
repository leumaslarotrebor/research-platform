<?php

namespace App\Http\Controllers;

use App\Models\Researcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResearcherController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Researcher::query()->paginate(20)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:researchers,email'],
            'affiliation' => ['nullable', 'string', 'max:255'],
            'orcid' => ['nullable', 'string', 'max:32'],
        ]);

        $researcher = Researcher::create($validated);

        return response()->json($researcher, 201);
    }
}

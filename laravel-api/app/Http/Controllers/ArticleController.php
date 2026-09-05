<?php

namespace App\Http\Controllers;

use App\Models\Article;
use Illuminate\Http\JsonResponse;

class ArticleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Article::with('researcher')->paginate(20)
        );
    }

    public function show(Article $article): JsonResponse
    {
        return response()->json(
            $article->load('researcher')
        );
    }
}

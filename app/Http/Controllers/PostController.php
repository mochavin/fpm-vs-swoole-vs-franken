<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostController extends Controller
{
    public function index(): JsonResponse
    {
        $posts = Post::latest()->limit(20)->get();

        return response()->json([
            'data' => $posts,
            'count' => $posts->count(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $post = Post::findOrFail($id);

        return response()->json([
            'data' => $post,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $post = Post::create($validated);

        return response()->json([
            'data' => $post,
        ], 201);
    }
}

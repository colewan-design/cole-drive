<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class FileController extends Controller
{
    public function index(): Response
    {
        $files = File::latest()->get()->map(fn (File $file) => [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'name' => $file->original_name,
            'size' => $file->size,
            'human_size' => $file->human_size,
            'category' => $file->category,
            'mime_type' => $file->mime_type,
            'download_count' => $file->download_count,
            'download_url' => route('files.download', $file->uuid),
            'created_at' => $file->created_at->toIso8601String(),
        ]);

        $used = (int) $files->sum('size');
        $diskTotal = disk_total_space(storage_path()) ?: 0;
        $diskFree = disk_free_space(storage_path()) ?: 0;

        $categories = $files->groupBy('category')->map(fn ($group) => [
            'count' => $group->count(),
            'size' => $group->sum('size'),
        ]);

        return Inertia::render('Dashboard', [
            'files' => $files->values(),
            'storage' => [
                'used' => $used,
                'total' => $diskTotal,
                'free' => $diskFree,
            ],
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:1048576'],
        ]);

        $uploaded = $request->file('file');
        $path = $uploaded->store('uploads', 'local');

        $request->user()->files()->create([
            'original_name' => $uploaded->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $uploaded->getMimeType(),
            'size' => $uploaded->getSize(),
        ]);

        return back();
    }

    public function update(Request $request, File $file): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $file->update(['original_name' => trim($validated['name'])]);

        return back();
    }

    public function destroy(File $file): RedirectResponse
    {
        $file->delete();

        return back();
    }

    public function download(string $uuid)
    {
        $file = File::where('uuid', $uuid)->firstOrFail();

        $file->increment('download_count');

        return Storage::disk('local')->download($file->path, $file->original_name);
    }
}

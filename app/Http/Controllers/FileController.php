<?php

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

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
        $extension = $this->normalizeExtension($uploaded->getClientOriginalExtension());
        $path = $uploaded->storeAs('uploads', Str::uuid().($extension === '' ? '' : '.'.$extension), 'local');

        $request->user()->files()->create([
            'original_name' => $uploaded->getClientOriginalName(),
            'path' => $path,
            'mime_type' => File::EXTENSION_MIME_OVERRIDES[$extension] ?? $uploaded->getMimeType(),
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
        $disk = Storage::disk('local');

        // A row whose file is gone would otherwise become a 500 under "php"
        // delivery and an opaque nginx 404 under "xaccel". `files:rebuild`
        // reports these; answering 404 here keeps the two paths consistent.
        abort_unless($disk->exists($file->path), 404);

        $file->increment('download_count');

        $headers = [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
        ];

        if (config('downloads.delivery') !== 'xaccel') {
            return $disk->download($file->path, $file->original_name, $headers);
        }

        // Hand the transfer to nginx. It streams the file straight off disk
        // while this worker is released for the next request, and it answers
        // Range requests itself so interrupted downloads can resume.
        //
        // Content-Length is deliberately not set: nginx derives it from the
        // file it serves, and a stale value here would truncate the response.
        return response('', 200, $headers + [
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file->original_name,
                Str::ascii($file->original_name) ?: 'download',
            ),
            'X-Accel-Redirect' => rtrim(config('downloads.xaccel_prefix'), '/').'/'.$this->encodePath($file->path),
        ]);
    }

    /**
     * Reduce a client-supplied extension to characters that are safe in a
     * stored filename. The stored name is later handed back to nginx as an
     * X-Accel-Redirect URI, so anything exotic here has to survive a second
     * round of URI parsing on the way out.
     */
    private function normalizeExtension(string $extension): string
    {
        return substr(preg_replace('/[^a-z0-9]/', '', strtolower($extension)), 0, 16);
    }

    /**
     * Percent-encode each segment of a stored path. nginx decodes the
     * X-Accel-Redirect URI before resolving it against the internal location,
     * so the encoding has to be applied per segment to leave the separators
     * intact.
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}

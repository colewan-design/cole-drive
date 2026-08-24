<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class FileController extends Controller
{
    public function index(Request $request): Response
    {
        $files = $request->user()->files()->latest()->get()->map(fn (File $file) => [
            'id' => $file->id,
            'uuid' => $file->uuid,
            'name' => $file->original_name,
            'size' => $file->size,
            'human_size' => $file->human_size,
            'category' => $file->category,
            'mime_type' => $file->mime_type,
            'download_count' => $file->download_count,
            'download_url' => route('files.download', $file->uuid),
            'preview_url' => route('files.preview', $file),
            'public_preview_url' => route('files.public-preview', $file->uuid),
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
        $file = $this->ownedFile($request->user(), $file);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $file->update(['original_name' => trim($validated['name'])]);

        return back();
    }

    public function destroy(Request $request, File $file): RedirectResponse
    {
        $file = $this->ownedFile($request->user(), $file);

        $file->delete();

        return back();
    }

    public function preview(Request $request, File $file)
    {
        $file = $this->ownedFile($request->user(), $file);

        abort_unless($this->isPreviewable($file), 415);

        return $this->serveFile($file, HeaderUtils::DISPOSITION_INLINE);
    }

    public function publicPreview(string $uuid)
    {
        $file = File::where('uuid', $uuid)->firstOrFail();

        abort_unless($this->isOfficePreviewable($file), 415);

        return $this->serveFile($file, HeaderUtils::DISPOSITION_INLINE);
    }

    public function download(string $uuid)
    {
        $file = File::where('uuid', $uuid)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($file->path), 404);
        $file->increment('download_count');

        return $this->serveFile($file, HeaderUtils::DISPOSITION_ATTACHMENT);
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

    private function serveFile(File $file, string $disposition)
    {
        $disk = Storage::disk('local');

        // A row whose file is gone would otherwise become a 500 under "php"
        // delivery and an opaque nginx 404 under "xaccel". `files:rebuild`
        // reports these; answering 404 here keeps the two paths consistent.
        abort_unless($disk->exists($file->path), 404);

        $headers = [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $disposition,
                $file->original_name,
                Str::ascii($file->original_name) ?: 'download',
            ),
        ];

        if (config('downloads.delivery') !== 'xaccel') {
            return $disposition === HeaderUtils::DISPOSITION_ATTACHMENT
                ? $disk->download($file->path, $file->original_name, $headers)
                : response()->file($disk->path($file->path), $headers);
        }

        // Hand the transfer to nginx. It streams the file straight off disk
        // while this worker is released for the next request, and it answers
        // Range requests itself so interrupted downloads can resume.
        //
        // Content-Length is deliberately not set: nginx derives it from the
        // file it serves, and a stale value here would truncate the response.
        return response('', 200, $headers + [
            'X-Accel-Redirect' => rtrim(config('downloads.xaccel_prefix'), '/').'/'.$this->encodePath($file->path),
        ]);
    }

    private function isPreviewable(File $file): bool
    {
        $mime = $file->mime_type ?? '';

        return str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'video/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'text/')
            || $this->isOfficePreviewable($file)
            || in_array($mime, [
                'application/pdf',
                'application/json',
                'application/xml',
            ], true);
    }

    private function isOfficePreviewable(File $file): bool
    {
        return in_array($file->mime_type, [
            'application/msword',
            'application/rtf',
            'text/rtf',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true);
    }

    private function ownedFile(User $user, File $file): File
    {
        abort_unless($file->user_id === $user->id, 404);

        return $file;
    }
}

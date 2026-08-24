<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class RebuildFilesFromDisk extends Command
{
    protected $signature = 'files:rebuild
                            {--user= : Email of the user the recovered files belong to}
                            {--dry-run : Report what would be recovered without touching the database}';

    protected $description = 'Rebuild the files table from the uploads still on disk, after a database loss';

    public function handle(): int
    {
        $owner = $this->resolveOwner();

        if (! $owner) {
            $this->error('No user found to own the recovered files.');
            $this->line('Run `php artisan db:seed` first, or pass --user=someone@example.com.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $onDisk = $disk->files('uploads');

        if ($onDisk === []) {
            $this->warn('No uploads found in '.storage_path('app/private/uploads').'.');
            $this->line('If the files are gone too, restore storage/app/private from the server snapshot.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $known = File::pluck('id', 'path');
        $rows = [];
        $recovered = 0;

        foreach ($onDisk as $path) {
            if ($known->has($path)) {
                continue;
            }

            $name = basename($path);
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $timestamp = Carbon::createFromTimestamp($disk->lastModified($path));

            // Uploads are stored as "<uuid>.<ext>", so reusing the stem as the uuid
            // keeps every download link handed out before the wipe working.
            $uuid = Str::isUuid($stem) ? $stem : (string) Str::uuid();

            $attributes = [
                'uuid' => $uuid,
                'user_id' => $owner->id,
                // The original filename only ever lived in the database. The stored
                // name is the best stand-in; the dashboard's rename tool fixes it.
                'original_name' => $name,
                'path' => $path,
                'mime_type' => File::EXTENSION_MIME_OVERRIDES[$extension] ?? ($disk->mimeType($path) ?: null),
                'size' => $disk->size($path),
                'download_count' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (! $dryRun) {
                (new File)->forceFill($attributes)->save();
            }

            $recovered++;
            $rows[] = [
                $name,
                (new File($attributes))->human_size,
                $attributes['mime_type'] ?? 'unknown',
                Str::isUuid($stem) ? 'kept' : 'new',
            ];
        }

        if ($rows !== []) {
            $this->table(['File', 'Size', 'MIME type', 'Link'], $rows);
        }

        $this->info(sprintf(
            '%s %d file(s); %d already in the database.',
            $dryRun ? 'Would recover' : 'Recovered',
            $recovered,
            count($onDisk) - $recovered,
        ));

        if ($dryRun) {
            $this->line('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        $this->reportMissingFiles($disk->files('uploads'));

        return self::SUCCESS;
    }

    private function resolveOwner(): ?User
    {
        $email = $this->option('user');

        if ($email) {
            return User::where('email', $email)->first();
        }

        return User::oldest('id')->first();
    }

    /**
     * Warn about rows whose file is no longer on disk — the reverse of a wipe,
     * and a sign the storage directory lost data too.
     *
     * @param  array<int, string>  $onDisk
     */
    private function reportMissingFiles(array $onDisk): void
    {
        $missing = File::pluck('path')->diff($onDisk);

        if ($missing->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->warn($missing->count().' database row(s) point at files that are not on disk:');

        foreach ($missing as $path) {
            $this->line('  - '.$path);
        }
    }
}

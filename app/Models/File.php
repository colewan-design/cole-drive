<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class File extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'original_name',
        'path',
        'mime_type',
        'size',
        'download_count',
    ];

    protected static function booted(): void
    {
        static::creating(function (File $file) {
            $file->uuid ??= (string) Str::uuid();
        });

        static::deleting(function (File $file) {
            Storage::disk('local')->delete($file->path);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getCategoryAttribute(): string
    {
        $mime = $this->mime_type ?? '';

        return match (true) {
            str_starts_with($mime, 'image/') => 'Pictures',
            str_starts_with($mime, 'video/') => 'Video',
            str_starts_with($mime, 'audio/') => 'Audio',
            in_array($mime, ['application/pdf', 'application/msword', 'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']) => 'Documents',
            in_array($mime, ['application/zip', 'application/vnd.android.package-archive',
                'application/x-msdownload', 'application/vnd.microsoft.portable-executable']) => 'Apps & Archives',
            default => 'Other',
        };
    }

    public function getHumanSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $bytes < 10 ? 1 : 0).' '.$units[$i];
    }
}

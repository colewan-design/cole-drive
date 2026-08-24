<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileDownloadTest extends TestCase
{
    use RefreshDatabase;

    private function storedFile(array $attributes = []): File
    {
        Storage::disk('local')->put('uploads/stored.bin', 'the-contents');

        return User::factory()->create()->files()->create(array_merge([
            'original_name' => 'holiday photos.zip',
            'path' => 'uploads/stored.bin',
            'mime_type' => 'application/zip',
            'size' => 12,
        ], $attributes));
    }

    public function test_php_delivery_streams_the_file_itself(): void
    {
        Storage::fake('local');
        config(['downloads.delivery' => 'php']);

        $file = $this->storedFile();

        $response = $this->get(route('files.download', $file->uuid));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $this->assertSame('the-contents', $response->streamedContent());
        $response->assertHeaderMissing('X-Accel-Redirect');
    }

    public function test_xaccel_delivery_hands_the_transfer_to_nginx(): void
    {
        Storage::fake('local');
        config(['downloads.delivery' => 'xaccel']);

        $file = $this->storedFile();

        $response = $this->get(route('files.download', $file->uuid));

        $response->assertOk();
        $response->assertHeader('X-Accel-Redirect', '/internal-downloads/uploads/stored.bin');
        $response->assertHeader('content-type', 'application/zip');

        // The body must stay empty: nginx replaces it with the file, and a
        // Content-Length from PHP would truncate that.
        $this->assertSame('', $response->getContent());
        $response->assertHeaderMissing('Content-Length');

        // The browser still needs the real filename, which only PHP knows.
        $this->assertStringContainsString('holiday photos.zip', $response->headers->get('Content-Disposition'));
    }

    public function test_xaccel_path_is_percent_encoded_per_segment(): void
    {
        Storage::fake('local');
        config(['downloads.delivery' => 'xaccel']);

        Storage::disk('local')->put('uploads/a file & more.bin', 'x');
        $file = $this->storedFile(['path' => 'uploads/a file & more.bin']);

        $response = $this->get(route('files.download', $file->uuid));

        // Separators survive; everything else is escaped, because nginx decodes
        // this URI before resolving it against the internal location.
        $response->assertHeader('X-Accel-Redirect', '/internal-downloads/uploads/a%20file%20%26%20more.bin');
    }

    public function test_a_row_whose_file_is_missing_returns_404(): void
    {
        Storage::fake('local');
        config(['downloads.delivery' => 'xaccel']);

        $file = $this->storedFile();
        Storage::disk('local')->delete('uploads/stored.bin');

        $this->get(route('files.download', $file->uuid))->assertNotFound();

        // A 404 must not look like a successful download in the stats.
        $this->assertSame(0, $file->fresh()->download_count);
    }

    public function test_downloads_are_counted(): void
    {
        Storage::fake('local');
        $file = $this->storedFile();

        $this->get(route('files.download', $file->uuid));
        $this->get(route('files.download', $file->uuid));

        $this->assertSame(2, $file->fresh()->download_count);
    }

    public function test_an_unknown_uuid_returns_404(): void
    {
        Storage::fake('local');

        $this->get(route('files.download', 'not-a-real-uuid'))->assertNotFound();
    }

    public function test_uploads_normalise_the_stored_extension(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('files.store'), ['file' => UploadedFile::fake()->create('Quarterly Report.PDF', 16)])
            ->assertRedirect();

        $file = File::firstOrFail();

        // The original name is preserved for the user; the name on disk is a
        // uuid plus a lowercased, alphanumeric-only extension, because it is
        // later handed back to nginx as a URI.
        $this->assertSame('Quarterly Report.PDF', $file->original_name);
        $this->assertMatchesRegularExpression('#^uploads/[0-9a-f-]{36}\.pdf$#', $file->path);
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_an_extensionless_upload_does_not_gain_a_trailing_dot(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('files.store'), ['file' => UploadedFile::fake()->create('README', 4)])
            ->assertRedirect();

        $this->assertMatchesRegularExpression('#^uploads/[0-9a-f-]{36}$#', File::firstOrFail()->path);
    }
}

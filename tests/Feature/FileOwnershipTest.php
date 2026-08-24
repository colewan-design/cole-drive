<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FileOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function ownedFile(User $owner, array $attributes = []): File
    {
        $path = $attributes['path'] ?? 'uploads/'.fake()->uuid().'.bin';
        Storage::disk('local')->put($path, 'owned-content');

        return $owner->files()->create(array_merge([
            'original_name' => 'Owned File.txt',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size' => 13,
        ], $attributes));
    }

    public function test_dashboard_only_lists_the_signed_in_users_files(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->ownedFile($owner, ['original_name' => 'Quarterly Plan.pdf']);
        $this->ownedFile($other, ['original_name' => 'Private Budget.xlsx']);

        $response = $this->actingAs($owner)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('files', 1)
            ->where('files.0.name', 'Quarterly Plan.pdf')
        );
    }

    public function test_users_cannot_rename_someone_elses_file(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = $this->ownedFile($owner);

        $this->actingAs($other)
            ->patch(route('files.update', $file), ['name' => 'Stolen.txt'])
            ->assertNotFound();

        $this->assertSame('Owned File.txt', $file->fresh()->original_name);
    }

    public function test_users_cannot_delete_someone_elses_file(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = $this->ownedFile($owner);

        $this->actingAs($other)
            ->delete(route('files.destroy', $file))
            ->assertNotFound();

        $this->assertNotNull($file->fresh());
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_users_cannot_preview_someone_elses_file_from_the_authenticated_route(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $file = $this->ownedFile($owner);

        $this->actingAs($other)
            ->get(route('files.preview', $file))
            ->assertNotFound();
    }
}

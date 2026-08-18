<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('ADMIN_NAME');
        putenv('ADMIN_EMAIL');
        putenv('ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_only_admins_can_access_user_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->get('/admin/users')->assertRedirect('/admin/login');
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->actingAs($viewer)->get('/admin/users')->assertForbidden();
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('id="admin-users"', false);
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk();
    }

    public function test_admin_can_create_update_and_delete_another_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $created = $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Petugas Baru',
            'email' => 'baru@example.test',
            'password' => 'password-baru',
        ])->assertCreated()->assertJsonPath('data.role', 'admin');

        $userId = $created->json('data.id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'email' => 'baru@example.test', 'role' => 'admin']);
        $this->assertTrue(Hash::check('password-baru', User::find($userId)->password));

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$userId, [
            'name' => 'Petugas Diperbarui',
            'email' => 'diperbarui@example.test',
        ])->assertOk()->assertJsonPath('data.name', 'Petugas Diperbarui');

        $this->assertTrue(Hash::check('password-baru', User::find($userId)->password));
        $this->actingAs($admin)->deleteJson('/api/admin/users/'.$userId)
            ->assertOk()
            ->assertJsonPath('message', 'Akun admin berhasil dihapus.');
        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_user_management_validates_password_and_blocks_self_delete(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson('/api/admin/users', [
            'name' => 'Admin Lemah',
            'email' => 'lemah@example.test',
            'password' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($admin)->deleteJson('/api/admin/users/'.$admin->id)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Akun yang sedang digunakan tidak dapat dihapus.');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_bootstrap_admin_does_not_overwrite_an_existing_password(): void
    {
        putenv('ADMIN_NAME=Bootstrap Admin');
        putenv('ADMIN_EMAIL=bootstrap@example.test');
        putenv('ADMIN_PASSWORD=first-password');

        $this->app->make(DatabaseSeeder::class)->run();
        $admin = User::where('email', 'bootstrap@example.test')->firstOrFail();
        $admin->update(['password' => Hash::make('changed-password')]);

        $this->app->make(DatabaseSeeder::class)->run();

        $admin = $admin->fresh();
        $this->assertSame('Bootstrap Admin', $admin->name);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('changed-password', $admin->password));
        $this->assertFalse(Hash::check('first-password', $admin->password));
    }
}

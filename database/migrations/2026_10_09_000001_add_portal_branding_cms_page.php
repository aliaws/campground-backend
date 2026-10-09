<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Adds the "portal-branding" CMS page: the logo, two-word app name and
 * tagline shown in the staff portal's top bar, editable by the superadmin
 * (Site Pages > Staff Portal). Created here rather than in CmsPageSeeder so
 * it exists without re-running the seeder, which would reset every other
 * page's content. An existing row is left untouched.
 */
return new class extends Migration
{
    private const SLUG = 'portal-branding';

    public function up(): void
    {
        if (DB::table('cms_pages')->where('slug', self::SLUG)->exists()) {
            return;
        }

        DB::table('cms_pages')->insert([
            'id' => (string) Str::ulid(),
            'slug' => self::SLUG,
            'title' => 'Staff Portal Branding',
            'content' => json_encode([
                'logo_url' => null,
                'site_title' => [
                    'primary_text' => 'Camp',
                    'secondary_text' => 'Manager',
                    'primary_color' => '#16301f',
                    'secondary_color' => '#f26522',
                    'primary_color_dark' => '#f3f1ea',
                    'secondary_color_dark' => '#ff7a3d',
                ],
                'tagline' => 'Reservations · Sites · Payments',
                'tagline_color' => '#5c6f63',
                'tagline_color_dark' => '#9fb0a5',
                'logo_style' => [
                    'background_color' => 'transparent',
                    'background_color_dark' => 'transparent',
                    'border_radius' => 10,
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('cms_pages')->where('slug', self::SLUG)->delete();
    }
};

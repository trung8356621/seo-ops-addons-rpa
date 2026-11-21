<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\PathPatternResolver;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Database-backed CMS Registry (sa_cms_types, sa_cms_path_templates)
 * and Site Override tracking on sa_paths.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        $conn = $this->connection;

        if (! Schema::connection($conn)->hasTable('sa_cms_types')) {
            Schema::connection($conn)->create('sa_cms_types', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 64);
                $table->string('name', 128);
                $table->text('description')->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();

                $table->unique('key');
                $table->index('is_enabled');
            });
        }

        if (! Schema::connection($conn)->hasTable('sa_cms_path_templates')) {
            Schema::connection($conn)->create('sa_cms_path_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cms_type_id');
                $table->string('cms_type_key', 64);
                $table->string('key', 64);
                $table->string('name', 128);
                $table->string('url_pattern', 255);
                $table->json('path_inputs')->nullable();
                $table->boolean('is_authentication')->default(false);
                $table->text('description')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();

                $table->unique(['cms_type_id', 'key'], 'sa_cms_path_templates_cms_key_unique');
                $table->index('cms_type_key');
            });
        }

        if (Schema::connection($conn)->hasTable('sa_paths')) {
            Schema::connection($conn)->table('sa_paths', function (Blueprint $table) use ($conn): void {
                if (! Schema::connection($conn)->hasColumn('sa_paths', 'cms_template_id')) {
                    $table->unsignedBigInteger('cms_template_id')->nullable()->after('site_key');
                    $table->index('cms_template_id');
                }
                if (! Schema::connection($conn)->hasColumn('sa_paths', 'is_override')) {
                    $table->boolean('is_override')->default(false)->after('is_authentication');
                }
                if (! Schema::connection($conn)->hasColumn('sa_paths', 'template_version')) {
                    $table->unsignedInteger('template_version')->nullable()->after('is_override');
                }
            });
        }

        // Seed WordPress CMS Type and its default Path Templates idempotently
        $now = now();
        $existingWp = DB::connection($conn)->table('sa_cms_types')->where('key', 'wordpress')->first();
        if (! $existingWp) {
            $wpId = DB::connection($conn)->table('sa_cms_types')->insertGetId([
                'key' => 'wordpress',
                'name' => 'WordPress',
                'description' => 'WordPress CMS',
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $wpId = (int) $existingWp->id;
        }

        $templates = [
            [
                'key' => 'create_post',
                'name' => 'Create Post',
                'url_pattern' => '/wp-admin/post-new.php',
                'is_authentication' => false,
                'description' => 'WordPress Create Post',
            ],
            [
                'key' => 'login',
                'name' => 'Login',
                'url_pattern' => '/wp-login.php',
                'is_authentication' => true,
                'description' => 'WordPress Login (Authentication)',
            ],
        ];

        foreach ($templates as $tpl) {
            $exists = DB::connection($conn)->table('sa_cms_path_templates')
                ->where('cms_type_id', $wpId)
                ->where('key', $tpl['key'])
                ->exists();
            if (! $exists) {
                DB::connection($conn)->table('sa_cms_path_templates')->insert([
                    'cms_type_id' => $wpId,
                    'cms_type_key' => 'wordpress',
                    'key' => $tpl['key'],
                    'name' => $tpl['name'],
                    'url_pattern' => $tpl['url_pattern'],
                    'path_inputs' => json_encode(PathPatternResolver::parseInputs($tpl['url_pattern'])),
                    'is_authentication' => $tpl['is_authentication'],
                    'description' => $tpl['description'],
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $conn = $this->connection;

        if (Schema::connection($conn)->hasTable('sa_paths')) {
            Schema::connection($conn)->table('sa_paths', function (Blueprint $table) use ($conn): void {
                if (Schema::connection($conn)->hasColumn('sa_paths', 'template_version')) {
                    $table->dropColumn('template_version');
                }
                if (Schema::connection($conn)->hasColumn('sa_paths', 'is_override')) {
                    $table->dropColumn('is_override');
                }
                if (Schema::connection($conn)->hasColumn('sa_paths', 'cms_template_id')) {
                    try {
                        $table->dropIndex(['cms_template_id']);
                    } catch (\Throwable) {
                    }
                    $table->dropColumn('cms_template_id');
                }
            });
        }

        Schema::connection($conn)->dropIfExists('sa_cms_path_templates');
        Schema::connection($conn)->dropIfExists('sa_cms_types');
    }
};

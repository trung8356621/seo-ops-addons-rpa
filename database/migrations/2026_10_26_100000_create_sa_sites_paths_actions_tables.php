<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;

/**
 * Redesign Action Registry: Sites, Paths, Actions on omi_social_automation.
 * Non-destructive and backward-compatible.
 */
return new class extends Migration
{
    protected $connection = SocialAutomationServiceConfig::CONNECTION;

    public function up(): void
    {
        // 1. Sites Registry
        if (! Schema::connection($this->connection)->hasTable('sa_sites')) {
            Schema::connection($this->connection)->create('sa_sites', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 64);
                $table->string('name', 128);
                $table->string('base_url', 255);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique('key');
            });
        }

        // 2. Paths Registry
        if (! Schema::connection($this->connection)->hasTable('sa_paths')) {
            Schema::connection($this->connection)->create('sa_paths', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('site_key', 64);
                $table->string('key', 64);
                $table->string('name', 128);
                $table->string('url_pattern', 255);
                $table->json('path_inputs')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'key'], 'sa_paths_site_key_unique');
                $table->index('site_key');
            });
        }

        // 3. Actions Registry
        if (! Schema::connection($this->connection)->hasTable('sa_actions')) {
            Schema::connection($this->connection)->create('sa_actions', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 64);
                $table->string('name', 128);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique('key');
            });
        }

        // 4. Update sa_action_tags with Site + Path + Action references
        if (Schema::connection($this->connection)->hasTable('sa_action_tags')) {
            Schema::connection($this->connection)->table('sa_action_tags', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'site_id')) {
                    $table->unsignedBigInteger('site_id')->nullable()->after('tag_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'site_key')) {
                    $table->string('site_key', 64)->nullable()->after('site_id');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'path_id')) {
                    $table->unsignedBigInteger('path_id')->nullable()->after('site_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'path_key')) {
                    $table->string('path_key', 64)->nullable()->after('path_id');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'action_id')) {
                    $table->unsignedBigInteger('action_id')->nullable()->after('path_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_action_tags', 'action_key')) {
                    $table->string('action_key', 64)->nullable()->after('action_id');
                }
            });
        }

        // 5. Update sa_script_requests with Path Inputs and Site/Path contract info
        if (Schema::connection($this->connection)->hasTable('sa_script_requests')) {
            Schema::connection($this->connection)->table('sa_script_requests', function (Blueprint $table): void {
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'site_key')) {
                    $table->string('site_key', 64)->nullable()->after('action_tag_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'site_base_url')) {
                    $table->string('site_base_url', 255)->nullable()->after('site_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'path_key')) {
                    $table->string('path_key', 64)->nullable()->after('site_base_url');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'url_pattern')) {
                    $table->string('url_pattern', 255)->nullable()->after('path_key');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'path_inputs')) {
                    $table->json('path_inputs')->nullable()->after('url_pattern');
                }
                if (! Schema::connection($this->connection)->hasColumn('sa_script_requests', 'action_key')) {
                    $table->string('action_key', 64)->nullable()->after('path_inputs');
                }
            });
        }

        // 6. Seed V1 Actions (Post, Comment, Collect - NO Like, NO Share!)
        $now = now();
        $actions = [
            ['key' => 'post', 'name' => 'Post', 'description' => 'Publish posts or status updates', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'comment', 'name' => 'Comment', 'description' => 'Publish comments on items', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'collect', 'name' => 'Collect', 'description' => 'Collect posts, comments or data', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($actions as $act) {
            DB::connection($this->connection)->table('sa_actions')->updateOrInsert(['key' => $act['key']], $act);
        }

        // 7. Seed V1 Sites (Facebook, X, Reddit)
        $sites = [
            ['key' => 'facebook', 'name' => 'Facebook', 'base_url' => 'https://www.facebook.com', 'description' => 'Facebook social network', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'x', 'name' => 'X / Twitter', 'base_url' => 'https://twitter.com', 'description' => 'X (formerly Twitter)', 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'reddit', 'name' => 'Reddit', 'base_url' => 'https://www.reddit.com', 'description' => 'Reddit communities and discussions', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($sites as $siteData) {
            DB::connection($this->connection)->table('sa_sites')->updateOrInsert(['key' => $siteData['key']], $siteData);
        }

        // 8. Seed V1 Paths
        $fbSite = DB::connection($this->connection)->table('sa_sites')->where('key', 'facebook')->first();
        if ($fbSite) {
            $fbPaths = [
                [
                    'site_id' => $fbSite->id,
                    'site_key' => 'facebook',
                    'key' => 'normal',
                    'name' => 'Normal',
                    'url_pattern' => '/',
                    'path_inputs' => json_encode([]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'site_id' => $fbSite->id,
                    'site_key' => 'facebook',
                    'key' => 'group',
                    'name' => 'Group',
                    'url_pattern' => '/groups/{group_id}',
                    'path_inputs' => json_encode([['key' => 'group_id', 'type' => 'string', 'required' => true]]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'site_id' => $fbSite->id,
                    'site_key' => 'facebook',
                    'key' => 'group_post_detail',
                    'name' => 'Group Post Detail',
                    'url_pattern' => '/groups/{group_id}/posts/{post_id}',
                    'path_inputs' => json_encode([
                        ['key' => 'group_id', 'type' => 'string', 'required' => true],
                        ['key' => 'post_id', 'type' => 'string', 'required' => true],
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];

            foreach ($fbPaths as $p) {
                DB::connection($this->connection)->table('sa_paths')->updateOrInsert(
                    ['site_id' => $p['site_id'], 'key' => $p['key']],
                    $p
                );
            }
        }

        $xSite = DB::connection($this->connection)->table('sa_sites')->where('key', 'x')->first();
        if ($xSite) {
            DB::connection($this->connection)->table('sa_paths')->updateOrInsert(
                ['site_id' => $xSite->id, 'key' => 'profile'],
                [
                    'site_id' => $xSite->id,
                    'site_key' => 'x',
                    'key' => 'profile',
                    'name' => 'Profile',
                    'url_pattern' => '/{username}',
                    'path_inputs' => json_encode([['key' => 'username', 'type' => 'string', 'required' => true]]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_paths');
        Schema::connection($this->connection)->dropIfExists('sa_sites');
        Schema::connection($this->connection)->dropIfExists('sa_actions');
    }
};

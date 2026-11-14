<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('page', 32)->default('general')->index();   // map | dashboard | monitoring | attendance | general
            $t->string('source', 16)->default('manual');            // auto (snapshot) | manual | reference
            $t->string('slug')->nullable()->unique();               // stable key for auto snapshots / references
            $t->longText('content');
            $t->jsonb('tags')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // 'simple' config: content mixes Malay and English, so no language-specific stemming
        DB::statement("ALTER TABLE knowledge_base ADD COLUMN search tsvector GENERATED ALWAYS AS
            (setweight(to_tsvector('simple', coalesce(title,'')), 'A') || setweight(to_tsvector('simple', coalesce(content,'')), 'B')) STORED");
        DB::statement('CREATE INDEX knowledge_base_search_gin ON knowledge_base USING gin(search)');

        Schema::create('ask_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('page', 32)->nullable();
            $t->text('question');
            $t->longText('answer')->nullable();
            $t->string('mode', 16);              // ai | search
            $t->jsonb('kb_ids')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_logs');
        Schema::dropIfExists('knowledge_base');
    }
};

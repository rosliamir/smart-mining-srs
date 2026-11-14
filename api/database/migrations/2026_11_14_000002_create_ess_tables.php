<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Employee Self-Service: leave, overtime, time-off, claims, approvals, notifications, memos, holidays. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $t) {
            $t->decimal('lng', 12, 6)->nullable();
            $t->decimal('out_lat', 12, 6)->nullable();
            $t->decimal('out_lng', 12, 6)->nullable();
            $t->string('source', 16)->nullable();      // legacy | ess
            $t->string('note')->nullable();
        });

        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 24)->unique();
            $t->string('name');
            $t->string('name_ms');
            $t->string('icon', 64)->default('fa-solid fa-umbrella-beach');
            $t->string('color', 16)->default('#2563eb');
            $t->decimal('default_days', 6, 1)->default(0);  // yearly entitlement when not service-based
            $t->boolean('service_based')->default(false);    // annual / sick: depends on years of service
            $t->boolean('requires_attachment')->default(false);
            $t->unsignedSmallInteger('min_notice_days')->default(0);
            $t->unsignedSmallInteger('max_backdate_days')->default(0);
            $t->jsonb('approval_chain');                      // e.g. ["supervisor","hr"]
            $t->boolean('deducts_balance')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('leave_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->decimal('entitled', 6, 1)->default(0);
            $t->decimal('carried', 6, 1)->default(0);
            $t->decimal('used', 6, 1)->default(0);
            $t->timestamps();
            $t->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('ess_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 16)->index();                 // leave | overtime | timeoff | claim
            $t->foreignId('leave_type_id')->nullable()->constrained()->nullOnDelete();
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->time('start_time')->nullable();
            $t->time('end_time')->nullable();
            $t->decimal('quantity', 10, 2)->default(0);     // days | hours | MYR
            $t->string('unit', 8);
            $t->string('half_day', 4)->nullable();           // am | pm
            $t->text('reason')->nullable();
            $t->jsonb('details')->nullable();
            $t->string('status', 16)->default('pending')->index();   // pending | approved | rejected | cancelled
            $t->unsignedSmallInteger('current_step')->default(1);
            $t->timestamps();
        });

        Schema::create('ess_request_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ess_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('step');
            $t->string('role', 16);                           // supervisor | hr
            $t->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 16)->default('pending');     // pending | approved | rejected | skipped
            $t->text('comment')->nullable();
            $t->timestamp('acted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('ess_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type', 24);
            $t->string('icon', 64)->nullable();
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('memos', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->longText('body');
            $t->string('category', 32)->default('general');   // general | hr | finance | safety | operations | it
            $t->string('priority', 16)->default('normal');    // normal | important | urgent
            $t->boolean('pinned')->default(false);
            $t->boolean('requires_ack')->default(false);
            $t->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        Schema::create('memo_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('memo_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('acked_at')->nullable();
            $t->unique(['memo_id', 'user_id']);
        });

        Schema::create('public_holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->index();
            $t->string('name');
            $t->string('scope', 16)->default('national');     // national | selangor
            $t->boolean('provisional')->default(false);
            $t->timestamps();
            $t->unique(['date', 'name']);
        });
    }

    public function down(): void
    {
        foreach (['public_holidays', 'memo_reads', 'memos', 'ess_notifications', 'ess_request_steps', 'ess_requests', 'leave_balances', 'leave_types'] as $t) Schema::dropIfExists($t);
        Schema::table('attendances', fn (Blueprint $t) => $t->dropColumn(['lng', 'out_lat', 'out_lng', 'source', 'note']));
    }
};

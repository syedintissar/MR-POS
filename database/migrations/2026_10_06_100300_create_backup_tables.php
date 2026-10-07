<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('tier', 20);                       // daily, weekly, monthly, manual, safety, uploaded
            $table->string('status', 20)->default('running'); // running, success, failed
            $table->string('filename', 255)->nullable();
            $table->string('disk_path', 500)->nullable();     // relative to the backup root
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->boolean('encrypted')->default(false);
            $table->boolean('includes_files')->default(false);
            $table->string('db_schema_version', 255)->nullable(); // last migration name at backup time
            $table->string('app_version', 20)->nullable();
            $table->longText('row_counts')->nullable();       // JSON: table => rows
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('verify_status', 20)->default('pending'); // pending, passed, failed
            $table->string('verify_level', 10)->nullable();   // quick, deep
            $table->dateTime('verified_at')->nullable();
            $table->text('verify_notes')->nullable();
            $table->text('error_message')->nullable();
            $table->boolean('is_protected')->default(false);
            $table->foreignId('triggered_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('pruned_at')->nullable();
            $table->timestamps();

            $table->index(['tier', 'status', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('backup_destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 20);                     // local_path (now); sftp, s3, rclone (Phase 9)
            $table->text('config')->nullable();               // encrypted JSON
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_encryption')->default(false);
            $table->dateTime('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained('backups')->restrictOnDelete();
            $table->foreignId('backup_destination_id')->constrained('backup_destinations')->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending, running, success, failed
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->string('remote_path', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['backup_id', 'backup_destination_id']);
            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('backup_restores', function (Blueprint $table) {
            $table->id();
            // Not a foreign key: after a restore the backup row may not exist in the restored database.
            $table->unsignedBigInteger('backup_id')->nullable();
            $table->string('source_filename', 255);
            $table->string('safety_backup_filename', 255)->nullable();
            $table->string('status', 20);                     // success, failed, rolled_back
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('backup_transfers');
        Schema::dropIfExists('backup_destinations');
        Schema::dropIfExists('backups');
    }
};
